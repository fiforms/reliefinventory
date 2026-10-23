<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Services\TrainingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use League\Csv\Writer;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Training report cards: for each trainee, which of the six stations (TrainingMode::STATIONS) they did real work
 * at in a date range (default today — one class), from activity_log (App\Services\ActivityRecorder). A station is
 * done with at least one action there. A Driver Portal delivery is logged against the team's driver, so it counts
 * for every trainee who worked on that team in the range. Certificates print for trainees who did all six.
 */
class TrainingReportController extends Controller
{
    public const FILTER_RULES = [
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        'team' => 'nullable|integer|between:1,5',
        'person_id' => 'nullable|integer',
    ];

    /** Human labels for the actions a report card lists. */
    public const ACTION_LABELS = [
        'receiving.intake' => 'Recorded an intake',
        'receiving.offer_offered' => 'Logged a donation offer',
        'receiving.offer_pending' => 'Accepted a donation offer',
        'receiving.offer_received' => 'Matched an offer to its arrival',
        'sorting.line' => 'Sorted a line',
        'sorting.completed' => 'Completed a sorting session',
        'inventory.put_on_pallet' => 'Put items on a pallet',
        'picking.started' => 'Started filling an order',
        'picking.fill' => 'Picked a line',
        'picking.completed' => 'Completed filling an order',
        'shipping.driver_assigned' => 'Assigned a driver',
        'shipping.shipped' => 'Marked a load shipped',
        'ordering.request_created' => 'Started a partner request',
        'ordering.request_submitted' => 'Submitted a partner request',
        'delivery.bol_uploaded' => 'Delivered (uploaded a signed BOL)',
        'delivery.bol_approved' => 'Approved a signed BOL',
        'delivery.bol_rejected' => 'Rejected a signed BOL',
    ];

    public function index(Request $request)
    {
        return response()->json($this->buildReport($this->filters($request)));
    }

    public function pdf(Request $request)
    {
        return $this->pdfBuilder($this->filters($request));
    }

    public function certificates(Request $request)
    {
        return $this->certificatesBuilder($this->filters($request));
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function pdfBuilder(array $filters = []): PdfBuilder
    {
        return Pdf::view('reports.training.report-cards', ['report' => $this->buildReport($filters), 'generatedAt' => now()])
            ->driver('weasyprint')
            ->format('letter')
            ->name('training-report-cards-'.now()->format('Y-m-d').'.pdf');
    }

    /** One certificate page per trainee who completed all six stations (or just `person_id`'s, if given). */
    public function certificatesBuilder(array $filters = []): PdfBuilder
    {
        $report = $this->buildReport($filters);
        $trainees = collect($report['trainees'])->where('complete', true)->values();

        return Pdf::view('reports.training.certificate', ['report' => $report, 'trainees' => $trainees])
            ->driver('weasyprint')
            ->format('letter')
            ->landscape()
            ->name('training-certificates-'.now()->format('Y-m-d').'.pdf');
    }

    public function csv(Request $request)
    {
        $report = $this->buildReport($this->filters($request));

        $csv = Writer::fromString('');
        $csv->insertOne(['Trainee', 'Team', ...array_values(TrainingMode::STATIONS), 'Stations done', 'Complete']);
        foreach ($report['trainees'] as $t) {
            $csv->insertOne([$t['name'], $t['team'], ...array_map(fn ($s) => $s['count'], $t['stations']),
                $t['done'], $t['complete'] ? 'yes' : 'no']);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="training-report-cards-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function filters(Request $request): array
    {
        return self::validFilters($request->all());
    }

    /** Validated filters with today as the default range — also used by the print queue (DocumentRegistry). */
    public static function validFilters(array $input): array
    {
        $filters = array_filter(Validator::make($input, self::FILTER_RULES)->validate(), fn ($v) => $v !== null && $v !== '');
        $filters['from'] ??= now()->toDateString();
        $filters['to'] ??= $filters['from'];

        return $filters;
    }

    /**
     * @param  array{from?: string, to?: string, team?: int, person_id?: int}  $filters
     */
    public function buildReport(array $filters = []): array
    {
        $filters = self::validFilters($filters);
        $rows = ActivityLog::whereBetween('created_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])
            ->whereNotNull('station')
            ->get(['person_id', 'driver_id', 'training_team', 'station', 'action']);

        $driverRows = $rows->whereNull('person_id')->whereNotNull('training_team');
        $personRows = $rows->whereNotNull('person_id')->groupBy('person_id');

        $trainees = Person::where('is_trainee', true)
            ->when(isset($filters['person_id']), fn ($q) => $q->whereKey($filters['person_id']))
            ->orderBy('last_name')->orderBy('first_name')->get()
            ->map(function (Person $person) use ($personRows, $driverRows) {
                $own = $personRows->get($person->id, collect());
                // Teams this trainee worked on in the range (or is on now): their team driver's deliveries count too.
                $teams = $own->pluck('training_team')->filter()->push($person->training_team)->filter()->unique();
                $all = $own->concat($driverRows->whereIn('training_team', $teams->all()));

                return $this->card($person, $all, $own->isNotEmpty());
            })
            ->filter(fn ($card) => $card['active'])
            ->when(isset($filters['team']), fn (Collection $c) => $c->where('team', (int) $filters['team']))
            ->values();

        return [
            'trainees' => $trainees,
            'stations' => TrainingMode::STATIONS,
            'totals' => [
                'trainees' => $trainees->count(),
                'complete' => $trainees->where('complete', true)->count(),
            ],
            'filters' => $filters,
        ];
    }

    private function card(Person $person, Collection $rows, bool $active): array
    {
        $stations = [];
        foreach (TrainingMode::STATIONS as $number => $name) {
            $here = $rows->where('station', $number);
            $stations[$number] = [
                'name' => $name,
                'count' => $here->count(),
                'done' => $here->isNotEmpty(),
                'actions' => $here->countBy('action')
                    ->map(fn ($n, $action) => ['label' => self::ACTION_LABELS[$action] ?? $action, 'count' => $n])
                    ->values()->all(),
            ];
        }
        $done = collect($stations)->where('done', true)->count();

        return [
            'person_id' => $person->id,
            'name' => $person->full_name,
            'team' => $person->training_team,
            'stations' => $stations,
            'done' => $done,
            'complete' => $done === count(TrainingMode::STATIONS),
            'active' => $active,
        ];
    }
}
