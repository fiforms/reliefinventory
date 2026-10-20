<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ItemType;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Requested but not fulfilled: every order line whose filling is finished
 * but that got less than the partner asked for — what's needed, how much,
 * and where (by the partner's county and zip). Orders still being entered,
 * waiting to fill or mid-fill are left out: nothing has been decided about
 * them yet, so they aren't "unfulfilled" (the Outstanding Orders Report
 * covers those).
 *
 * "Not filled" is always measured against qty_requested, never the
 * approved quantity, and is split into the two reasons a line comes up
 * short: cut at allocation (requested − approved, a manager's decision in
 * Review Allocation) and short at picking (approved − filled, the picker
 * didn't find it). Partner names are deliberately left out — county and zip
 * are what the report is for.
 */
class UnfulfilledRequestsReportController extends Controller
{
    /** Filling is done in every one of these; Closed - Unfulfilled means nothing at all was picked. */
    public const FILLED_STATUSES = [
        Transaction::STATUS_FILLED,
        Transaction::STATUS_READY_TO_SHIP,
        Transaction::STATUS_SHIPPED,
        Transaction::STATUS_DELIVERED,
        Transaction::STATUS_DELIVERED_AWAITING_BOL,
        Transaction::STATUS_COMPLETED,
        Transaction::STATUS_CLOSED_UNFULFILLED,
    ];

    public const FILTER_RULES = [
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
    ];

    public function index(Request $request)
    {
        return response()->json($this->buildReport($this->filters($request)));
    }

    public function pdf(Request $request)
    {
        return $this->pdfBuilder($this->filters($request));
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function pdfBuilder(array $filters = []): PdfBuilder
    {
        $generatedAt = now();

        return Pdf::view('reports.unfulfilled', [
            'report' => $this->buildReport($filters),
            'filters' => $filters,
            'generatedAt' => $generatedAt,
        ])
            ->driver('weasyprint')
            ->format('letter')
            ->name('unfulfilled-requests-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    /** One row per item × county × zip — the finest grain, so it pivots cleanly in a spreadsheet. */
    public function csv(Request $request)
    {
        $report = $this->buildReport($this->filters($request));

        $csv = Writer::createFromString('');
        $csv->insertOne(['Item #', 'Item', 'Category', 'Unit', 'County', 'Zip', 'Requests', 'Qty Requested',
            'Qty Filled', 'Qty Not Filled', 'Cut at Allocation', 'Short at Picking']);
        foreach ($report['items'] as $item) {
            foreach ($item['locations'] as $loc) {
                $csv->insertOne([
                    $item['display_number'], $item['name'], $item['category'], $item['unit'],
                    $loc['county'], $loc['zip'], $loc['requests'], $loc['requested'],
                    $loc['filled'], $loc['not_filled'], $loc['cut_at_allocation'], $loc['short_at_picking'],
                ]);
            }
        }

        $filename = 'unfulfilled-requests-'.now()->format('Y-m-d').'.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate(self::FILTER_RULES));
    }

    /**
     * @param  array{from?: string, to?: string}  $filters  order_date range, inclusive
     */
    public function buildReport(array $filters = []): array
    {
        $lines = $this->shortLines($filters);

        $itemTypes = ItemType::with(['unit', 'category'])
            ->whereIn('id', $lines->pluck('itemtype_id')->unique())
            ->get()
            ->keyBy('id');

        $items = $lines->groupBy('itemtype_id')
            ->map(function (Collection $rows, $itemtypeId) use ($itemTypes) {
                $type = $itemTypes->get($itemtypeId);

                return [
                    'itemtype_id' => (int) $itemtypeId,
                    'display_number' => $type?->display_number,
                    'name' => $type?->name ?? '(deleted item type)',
                    'category' => $type?->category?->name,
                    'unit' => $type?->unit?->abbreviation ?? $type?->unit?->name,
                    ...$this->sums($rows),
                    'locations' => $rows->groupBy(fn ($r) => $r->county."\0".$r->zip)
                        ->map(fn (Collection $g) => [
                            'county' => $g->first()->county,
                            'zip' => $g->first()->zip,
                            ...$this->sums($g),
                        ])
                        ->sortByDesc('not_filled')
                        ->values(),
                ];
            })
            ->sortBy([['not_filled', 'desc'], ['name', 'asc']])
            ->values();

        return [
            'items' => $items,
            'by_county' => $this->places($lines, 'county'),
            'by_zip' => $this->places($lines, 'zip'),
            'totals' => [
                ...$this->sums($lines),
                'item_types' => $items->count(),
            ],
            'filters' => (object) $filters,
        ];
    }

    /**
     * Every finished order line that got less than was requested, with the
     * partner's county/zip. Filled = the line's fill records, summed (a
     * line can have several; see OrderFillingController).
     */
    private function shortLines(array $filters): Collection
    {
        $statusIds = collect(self::FILLED_STATUSES)->map(fn ($name) => Transaction::statusId($name));

        $fills = DB::table('item_ledgers')
            ->whereNotNull('order_line_id')
            ->groupBy('order_line_id')
            ->selectRaw('order_line_id, SUM(COALESCE(qty_subtracted, 0)) AS filled');

        return DB::table('orderlines')
            ->join('orderdonations', 'orderdonations.id', '=', 'orderlines.orderdonation_id')
            ->leftJoin('people', 'people.id', '=', 'orderdonations.person_id')
            ->leftJoin('counties', 'counties.id', '=', 'people.county_id')
            ->leftJoinSub($fills, 'fills', 'fills.order_line_id', '=', 'orderlines.id')
            ->where('orderdonations.type', 'order')
            ->whereIn('orderdonations.status_id', $statusIds)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('orderdonations.order_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('orderdonations.order_date', '<=', $to))
            ->whereRaw('COALESCE(fills.filled, 0) < orderlines.qty_requested')
            ->selectRaw("
                orderlines.itemtype_id,
                orderdonations.id AS order_id,
                COALESCE(counties.county, 'Unspecified') AS county,
                COALESCE(NULLIF(people.zip, ''), 'Unspecified') AS zip,
                orderlines.qty_requested AS requested,
                COALESCE(orderlines.qty_approved, orderlines.qty_requested) AS to_pick,
                COALESCE(fills.filled, 0) AS filled
            ")
            ->get();
    }

    /** Totals for a group of short lines; requests counts distinct orders, not lines. */
    private function sums(Collection $rows): array
    {
        $requested = (int) $rows->sum('requested');
        $filled = (int) $rows->sum('filled');
        // Short at picking can't go negative (a line picked past its approved
        // qty still fell short of the request); whatever isn't explained by
        // picking was cut at allocation.
        $shortAtPicking = (int) $rows->sum(fn ($r) => max(0, $r->to_pick - $r->filled));

        return [
            'requests' => $rows->pluck('order_id')->unique()->count(),
            'requested' => $requested,
            'filled' => $filled,
            'not_filled' => $requested - $filled,
            'cut_at_allocation' => $requested - $filled - $shortAtPicking,
            'short_at_picking' => $shortAtPicking,
        ];
    }

    private function places(Collection $lines, string $field): Collection
    {
        return $lines->groupBy($field)
            // The value comes from a row, not the group key: PHP turns a
            // numeric key like "98501" into an int.
            ->map(fn (Collection $rows) => [
                $field => $rows->first()->$field,
                'item_types' => $rows->pluck('itemtype_id')->unique()->count(),
                ...$this->sums($rows),
            ])
            ->sortByDesc('not_filled')
            ->values();
    }
}
