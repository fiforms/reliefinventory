<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DonorStandingLog;
use App\Models\Person;
use App\Services\DonorStanding;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use League\Csv\Writer;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Donor Report: what each donor sent, how much of it was usable, and how that has gone from donation to
 * donation — with a *suggested* standing (preferred / normal / watch / decline) next to the one staff have
 * actually set. Quality comes from the sorting ledger (item_ledgers additions on donation transactions, by
 * disposition); a donor's first bad shipment isn't a pattern, so a donor under the configured minimum
 * history gets "not enough data" instead of a score (config('inventory.donor_standing'), App\Services\DonorStanding).
 *
 * The standing is a stored, human decision on the person (people.donor_standing, logged in
 * donor_standing_log). It is shown at Receiving but never gates anything — a declined donor's truck can still
 * be unloaded; staff just see the warning. Setting it needs manage-donor-standing, separate from viewing the report.
 */
class DonorReportController extends Controller
{
    public const FILTER_RULES = [
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
    ];

    public function index(Request $request)
    {
        return response()->json([
            ...$this->buildReport($this->filters($request)),
            'can_set_standing' => (bool) Auth::user()?->hasPermission('manage-donor-standing'),
        ]);
    }

    public function pdf(Request $request)
    {
        return $this->pdfBuilder($this->filters($request));
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function pdfBuilder(array $filters = []): PdfBuilder
    {
        $generatedAt = now();

        return Pdf::view('reports.donors', [
            'report' => $this->buildReport($filters),
            'generatedAt' => $generatedAt,
        ])
            ->driver('weasyprint')
            ->format('letter')
            ->name('donor-report-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    /** One row per donor × donation, so loss trends pivot in a spreadsheet. */
    public function csv(Request $request)
    {
        $report = $this->buildReport($this->filters($request));

        $csv = Writer::fromString('');
        $csv->insertOne(['Donor', 'Standing', 'Suggested', 'Donor Loss %', 'Donation #', 'Date', 'Status',
            'Usable', 'Outdated', 'Trashed', 'Diverted', 'Donation Loss %']);
        foreach ($report['donors'] as $d) {
            foreach ($d['donation_list'] as $n) {
                $csv->insertOne([
                    $d['name'], $d['standing'], $d['suggested'] ?? 'not enough data', $d['loss_percent'],
                    $n['id'], $n['date'], $n['status'], $n['usable'], $n['outdated'], $n['trashed'], $n['diverted'], $n['loss_percent'],
                ]);
            }
        }

        $filename = 'donor-report-'.now()->format('Y-m-d').'.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Set a donor's standing (manage-donor-standing). Logged; never gates anything downstream. */
    public function setStanding(Request $request, $id)
    {
        $person = Person::findOrFail($id);
        abort_if($person->isSystem(), 422, 'The Unknown Donor placeholder has no standing.');

        $data = $request->validate([
            'standing' => ['required', Rule::in(DonorStanding::ALL)],
            // A decline is the serious one: say why, so the next person at the dock understands.
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('standing') === DonorStanding::DECLINE)],
        ]);

        DB::transaction(function () use ($person, $data) {
            $from = $person->donor_standing ?? DonorStanding::NORMAL;
            $person->forceFill([
                'donor_standing' => $data['standing'],
                'donor_standing_note' => $data['note'] ?? null,
                'donor_standing_set_by' => Auth::id(),
                'donor_standing_set_at' => now(),
            ])->save();

            DonorStandingLog::create([
                'person_id' => $person->id,
                'from_standing' => $from,
                'to_standing' => $data['standing'],
                'note' => $data['note'] ?? null,
                'person_id_user' => Auth::id(),
            ]);
        });

        return response()->json(['record' => $person->fresh()]);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate(self::FILTER_RULES));
    }

    /**
     * @param  array{from?: string, to?: string}  $filters  donation order_date range, inclusive
     */
    public function buildReport(array $filters = []): array
    {
        $rows = $this->donations($filters);

        $donors = $rows->groupBy('person_id')
            ->map(function (Collection $donations) {
                $first = $donations->first();
                // A donation with nothing sorted yet has no quality to speak of; count it, don't score it.
                $sorted = $donations->filter(fn ($d) => $d->total > 0)->sortBy('order_date')->values();

                $usable = (int) $sorted->sum('usable');
                $outdated = (int) $sorted->sum('outdated');
                $trashed = (int) $sorted->sum('trashed');
                $diverted = (int) $sorted->sum('diverted');
                $total = $usable + $outdated + $trashed + $diverted;
                $loss = DonorStanding::lossPercent($total, $outdated, $trashed);
                $suggestion = DonorStanding::suggest($sorted->count(), $total, $loss);
                $recent = $sorted->take(-3);

                return [
                    'person_id' => (int) $first->person_id,
                    'name' => $this->donorName($first),
                    'is_system' => $first->system_key !== null,
                    'standing' => $first->donor_standing ?? DonorStanding::NORMAL,
                    'standing_note' => $first->donor_standing_note,
                    'standing_set_at' => $first->donor_standing_set_at,
                    'donations' => $sorted->count(),
                    'unsorted_donations' => $donations->count() - $sorted->count(),
                    'units' => $total,
                    'usable' => $usable,
                    'outdated' => $outdated,
                    'trashed' => $trashed,
                    'diverted' => $diverted,
                    'loss_percent' => $loss,
                    'recent_loss_percent' => DonorStanding::lossPercent(
                        (int) $recent->sum('total'), (int) $recent->sum('outdated'), (int) $recent->sum('trashed')),
                    'last_donation' => $donations->max('order_date'),
                    'suggested' => $suggestion['standing'],
                    'suggestion_reason' => $suggestion['reason'],
                    'donation_list' => $donations->sortByDesc('order_date')->values()->map(fn ($d) => [
                        'id' => (int) $d->id,
                        'date' => $d->order_date,
                        'status' => $d->status,
                        'usable' => (int) $d->usable,
                        'outdated' => (int) $d->outdated,
                        'trashed' => (int) $d->trashed,
                        'diverted' => (int) $d->diverted,
                        'loss_percent' => DonorStanding::lossPercent((int) $d->total, (int) $d->outdated, (int) $d->trashed),
                    ]),
                ];
            })
            // Biggest givers first; the Unknown Donor placeholder sinks to the bottom whatever it holds.
            ->sortBy([['is_system', 'asc'], ['units', 'desc'], ['name', 'asc']])
            ->values();

        $scored = $donors->where('is_system', false);
        $total = (int) $donors->sum('units');

        return [
            'filters' => (object) $filters,
            'donors' => $donors,
            'totals' => [
                'donors' => $donors->count(),
                'donations' => (int) $donors->sum('donations'),
                'units' => $total,
                'usable' => (int) $donors->sum('usable'),
                'outdated' => (int) $donors->sum('outdated'),
                'trashed' => (int) $donors->sum('trashed'),
                'diverted' => (int) $donors->sum('diverted'),
                'loss_percent' => DonorStanding::lossPercent($total, (int) $donors->sum('outdated'), (int) $donors->sum('trashed')),
                'watch' => $scored->where('standing', DonorStanding::WATCH)->count(),
                'decline' => $scored->where('standing', DonorStanding::DECLINE)->count(),
                // A suggestion that differs from what's set is the report's actual to-do list.
                'needs_review' => $scored->filter(fn ($d) => $d['suggested'] !== null && $d['suggested'] !== $d['standing']
                    && ! ($d['suggested'] === DonorStanding::NORMAL && $d['standing'] === DonorStanding::NORMAL))->count(),
            ],
            'thresholds' => config('inventory.donor_standing'),
        ];
    }

    private function donorName(object $row): string
    {
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return $row->organization ?: ($name !== '' ? $name : '(unnamed donor)');
    }

    /**
     * One row per donation with a donor, with its sorted quantities by disposition. Adjustments aren't donations
     * and sorting additions never carry stock_pallet_id (the same rule WarehouseMetrics uses).
     */
    private function donations(array $filters): Collection
    {
        $sorted = DB::table('item_ledgers')
            ->whereNull('stock_pallet_id')
            ->whereNotNull('qty_added')
            ->groupBy('orderdonation_id')
            ->selectRaw("
                orderdonation_id,
                SUM(CASE WHEN COALESCE(disposition, 'usable') = 'usable' THEN qty_added ELSE 0 END) AS usable,
                SUM(CASE WHEN disposition = 'outdated' THEN qty_added ELSE 0 END) AS outdated,
                SUM(CASE WHEN disposition = 'trashed' THEN qty_added ELSE 0 END) AS trashed,
                SUM(CASE WHEN disposition = 'diverted' THEN qty_added ELSE 0 END) AS diverted
            ");

        return DB::table('orderdonations')
            ->join('people', 'people.id', '=', 'orderdonations.person_id')
            ->leftJoin('statuses', 'statuses.id', '=', 'orderdonations.status_id')
            ->leftJoinSub($sorted, 'sorted', 'sorted.orderdonation_id', '=', 'orderdonations.id')
            ->where('orderdonations.type', 'donation')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('orderdonations.order_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('orderdonations.order_date', '<=', $to))
            ->selectRaw('
                orderdonations.id, orderdonations.person_id, orderdonations.order_date, statuses.name AS status,
                people.first_name, people.last_name, people.organization, people.system_key,
                people.donor_standing, people.donor_standing_note, people.donor_standing_set_at,
                COALESCE(sorted.usable, 0) AS usable, COALESCE(sorted.outdated, 0) AS outdated,
                COALESCE(sorted.trashed, 0) AS trashed, COALESCE(sorted.diverted, 0) AS diverted,
                COALESCE(sorted.usable, 0) + COALESCE(sorted.outdated, 0) + COALESCE(sorted.trashed, 0) + COALESCE(sorted.diverted, 0) AS total
            ')
            ->get();
    }
}
