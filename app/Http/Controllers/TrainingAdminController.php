<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\TrainingSetting;
use App\Models\TrustedDevice;
use App\Services\TrainingMode;
use App\Services\TrainingPreparer;
use App\Support\TrainingGuide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Setup > Training Mode (`manage-training`; switching the mode itself needs `admin-system`): the switch, the
 * trainee list, Prepare Training and its setup packet + labels, and the team activity sheets (those print from
 * /help for anyone signed in). See App\Services\TrainingMode.
 */
class TrainingAdminController extends Controller
{
    public function status()
    {
        $settings = TrainingSetting::current();
        $today = ActivityLog::where('created_at', '>=', now()->startOfDay())->whereNotNull('person_id')
            ->selectRaw('person_id, count(*) as n')->groupBy('person_id')->pluck('n', 'person_id');

        return response()->json([
            'enabled' => $settings->enabled,
            'enabled_at' => $settings->enabled_at,
            'prepared_at' => $settings->prepared_at,
            'prepared' => (bool) $settings->prepared_summary,
            'can_toggle' => Auth::user()->hasPermission('admin-system'),
            'approved_devices' => TrustedDevice::where('status', 'approved')->count(),
            'teams' => TrainingMode::TEAMS,
            'trainees' => Person::where('is_trainee', true)->orderBy('training_team')->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn (Person $p) => [
                    'id' => $p->id, 'first_name' => $p->first_name, 'last_name' => $p->last_name, 'full_name' => $p->full_name,
                    'team' => $p->training_team, 'active' => $p->disabled_at === null, 'actions_today' => (int) ($today[$p->id] ?? 0),
                ])->values(),
        ]);
    }

    public function setMode(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $settings = TrainingSetting::current();
        $settings->update($data['enabled']
            ? ['enabled' => true, 'enabled_at' => now(), 'enabled_by' => Auth::id()]
            : ['enabled' => false]);

        return $this->status();
    }

    public function prepare()
    {
        try {
            $summary = app(TrainingPreparer::class)->run();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['summary' => $summary, 'missing_items' => $summary['missing_items']]);
    }

    public function storeTrainee(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'team' => ['nullable', 'integer', Rule::in(TrainingMode::TEAMS)],
        ]);
        TrainingLoginController::createTrainee(trim($data['first_name']), trim($data['last_name']), $data['team'] ?? null);

        return $this->status();
    }

    /** Change a trainee's team, or take them off / put them back on the tap-your-name list. */
    public function updateTrainee(Request $request, int $id)
    {
        $data = $request->validate([
            'team' => ['nullable', 'integer', Rule::in(TrainingMode::TEAMS)],
            'active' => 'sometimes|boolean',
        ]);
        $person = Person::where('is_trainee', true)->findOrFail($id);
        if ($request->has('team')) {
            $person->training_team = $data['team'];
        }
        if (array_key_exists('active', $data)) {
            $person->disabled_at = $data['active'] ? null : now();
        }
        $person->save();

        return $this->status();
    }

    public function setupPacket()
    {
        return $this->setupPacketBuilder();
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function setupPacketBuilder(): PdfBuilder
    {
        $summary = TrainingSetting::current()->prepared_summary;
        abort_unless($summary, 404, 'Prepare Training has not been run yet.');

        return Pdf::view('reports.training.setup-packet', ['summary' => $summary])
            ->driver('weasyprint')
            ->format('letter')
            ->name('training-setup-packet.pdf');
    }

    public function teamSheets(Request $request)
    {
        $team = $request->validate(['team' => ['nullable', 'integer', Rule::in(TrainingMode::TEAMS)]])['team'] ?? null;

        return $this->teamSheetsBuilder($team);
    }

    /** One team's sheet, or every team's (null) in one PDF. */
    public function teamSheetsBuilder(?int $team = null): PdfBuilder
    {
        $drivers = collect(TrainingSetting::current()->prepared_summary['team_drivers'] ?? [])->keyBy('team')->all();
        $teams = $team ? [$team] : TrainingMode::TEAMS;

        return Pdf::view('reports.training.team-sheet', [
            'sheets' => array_map(fn (int $t) => TrainingGuide::sheetFor($t), $teams),
            'drivers' => $drivers,
        ])
            ->driver('weasyprint')
            ->format('letter')
            ->name($team ? "training-team-$team.pdf" : 'training-team-sheets.pdf');
    }
}
