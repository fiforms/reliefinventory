<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\TrainingRegistration;
use App\Models\TrainingSetting;
use App\Models\TrustedDevice;
use App\Services\TrainingMode;
use App\Services\TrainingPreparer;
use App\Support\TrainingGuide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use League\Csv\Writer;
use Milon\Barcode\DNS2D;
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
            'event_title' => $settings->event_title,
            'event_location' => $settings->event_location,
            'trainers' => array_map(fn (int $slot) => [
                'slot' => $slot,
                'name' => $settings->{"trainer{$slot}_name"},
                'has_signature' => (bool) $settings->{"trainer{$slot}_signature"},
                'signature_version' => $settings->{"trainer{$slot}_signature"} ? md5($settings->{"trainer{$slot}_signature"}) : null,
            ], TrainingSetting::TRAINER_SLOTS),
            'can_toggle' => Auth::user()->hasPermission('admin-system'),
            'approved_devices' => TrustedDevice::where('status', 'approved')->count(),
            'teams' => TrainingMode::TEAMS,
            'signup' => [
                'url' => route('join'),
                'qr_svg' => self::signupQrSvg(),
                'today' => TrainingRegistration::where('created_at', '>=', now()->startOfDay())->count(),
                'total' => TrainingRegistration::count(),
            ],
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

    /** The event title, location and trainer names printed on the certificates. */
    public function setEvent(Request $request)
    {
        $data = $request->validate([
            'event_title' => 'nullable|string|max:150',
            'event_location' => 'nullable|string|max:150',
            'trainer1_name' => 'nullable|string|max:100',
            'trainer2_name' => 'nullable|string|max:100',
        ]);
        TrainingSetting::current()->update(array_map(fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, $data));

        return $this->status();
    }

    /** A trainer's signature image for the certificates (PNG or JPEG; replaces any earlier one for that trainer). */
    public function uploadSignature(Request $request, int $slot)
    {
        abort_unless(in_array($slot, TrainingSetting::TRAINER_SLOTS, true), 404);
        $request->validate(['signature' => 'required|file|mimes:png,jpg,jpeg|max:2048']);

        $settings = TrainingSetting::current();
        $old = $settings->{"trainer{$slot}_signature"};
        $settings->update(["trainer{$slot}_signature" => $request->file('signature')->store('training-signatures', 'local')]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $this->status();
    }

    public function deleteSignature(int $slot)
    {
        abort_unless(in_array($slot, TrainingSetting::TRAINER_SLOTS, true), 404);
        $settings = TrainingSetting::current();
        if ($path = $settings->{"trainer{$slot}_signature"}) {
            Storage::disk('local')->delete($path);
            $settings->update(["trainer{$slot}_signature" => null]);
        }

        return $this->status();
    }

    /** The uploaded signature image itself, for the preview on the Training Mode page. */
    public function signature(int $slot)
    {
        abort_unless(in_array($slot, TrainingSetting::TRAINER_SLOTS, true), 404);
        $path = TrainingSetting::current()->{"trainer{$slot}_signature"};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
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

    /** The /join sign-up URL as a QR code (SVG) — on the Training Mode page and the poster. */
    public static function signupQrSvg(int $size = 6): string
    {
        $svg = (new DNS2D)->getBarcodeSVG(route('join'), 'QRCODE', $size, $size);

        return preg_replace('/^.*?(<svg)/s', '$1', $svg); // drop the XML prolog/doctype so it can be inlined
    }

    public function signupPoster()
    {
        return $this->signupPosterBuilder();
    }

    /** Unrendered PDF — shared by the download route and the print queue. A page to show or post: QR + URL. */
    public function signupPosterBuilder(): PdfBuilder
    {
        return Pdf::view('reports.training.signup-poster', ['qr' => self::signupQrSvg(14), 'url' => route('join')])
            ->driver('weasyprint')
            ->format('letter')
            ->name('training-signup-poster.pdf');
    }

    /** Everyone who signed up at /join, with their follow-up answers — for contacting them later. */
    public function signupsCsv()
    {
        $csv = Writer::fromString('');
        $csv->insertOne(['Signed up', 'First name', 'Last name', 'Email', 'Phone', 'Wants updates', 'Volunteer at a warehouse',
            'Software / IT', 'Bring to my organization', 'Other', 'Recommends', 'Comments', 'Team']);
        foreach (TrainingRegistration::with('person')->orderBy('created_at')->get() as $r) {
            $csv->insertOne([
                $r->created_at?->format('Y-m-d H:i'), $r->person?->first_name, $r->person?->last_name, $r->email, $r->phone,
                ...array_map(fn ($v) => $v ? 'yes' : '', [$r->wants_updates, $r->help_volunteer, $r->help_software, $r->help_organization, $r->help_other]),
                $r->recommend, $r->comments, $r->person?->training_team,
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="training-signups-'.now()->format('Y-m-d').'.csv"',
        ]);
    }
}
