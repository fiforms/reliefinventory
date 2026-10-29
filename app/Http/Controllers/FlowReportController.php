<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ItemType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Inventory Flow Report: what came in, what went out and what was lost over
 * a date range, bucketed by day/week/month, with a running on-hand line.
 *
 * Everything is read from item_ledgers, joined to its transaction's type:
 *  - donation  → received (usable additions) and lost (outdated / trashed /
 *                diverted additions — not stock, so they never touch on-hand);
 *  - order     → picked (fill records);
 *  - anything else (inventory adjustments) → adjustments, net, split by
 *                the adjustment's reason.
 * A row's effect on stock is (usable ? qty_added : 0) − qty_subtracted, the
 * same rule InventoryReportController uses, so opening + received − picked +
 * adjustments is exactly stock-on-hand at the end of the range. Moving a
 * pallet (pallet_stock_assignments) changes where stock is, not how much,
 * so it isn't here.
 */
class FlowReportController extends Controller
{
    public const FILTER_RULES = [
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        'bucket' => 'nullable|in:day,week,month',
        'category_id' => 'nullable|integer|exists:categories,id',
        'itemtype_id' => 'nullable|integer|exists:itemtypes,id',
    ];

    /** Longest range a daily/weekly/monthly chart will draw (keeps the chart and PDF readable). */
    public const MAX_BUCKETS = 400;

    public const DEFAULT_DAYS = 90;

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        if ($error = $this->rangeError($filters)) {
            return response()->json(['errors' => ['to' => [$error]], 'message' => $error], 422);
        }

        return response()->json([
            ...$this->buildReport($filters),
            'options' => [
                'categories' => Category::orderBy('name')->get(['id', 'name']),
                'itemtypes' => ItemType::orderBy('name')->get(['id', 'name', 'category_id']),
            ],
        ]);
    }

    public function pdf(Request $request)
    {
        $filters = $this->filters($request);
        abort_if($this->rangeError($filters), 422, 'Date range too long for this bucket size.');

        return $this->pdfBuilder($filters);
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function pdfBuilder(array $filters = []): PdfBuilder
    {
        $generatedAt = now();

        return Pdf::view('reports.flow', [
            'report' => $this->buildReport($filters),
            'generatedAt' => $generatedAt,
        ])
            ->driver('weasyprint')
            ->format('letter')
            ->name('inventory-flow-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    /** Per-item rows for the whole range (the finest grain, so it pivots in a spreadsheet). */
    public function csv(Request $request)
    {
        $filters = $this->filters($request);
        abort_if($this->rangeError($filters), 422, 'Date range too long for this bucket size.');
        $report = $this->buildReport($filters);

        $csv = Writer::fromString('');
        $csv->insertOne(['Item #', 'Item', 'Category', 'Unit', 'Opening', 'Received', 'Picked', 'Adjustments',
            'Closing', 'Outdated', 'Trashed', 'Diverted']);
        foreach ($report['items'] as $item) {
            $csv->insertOne([
                $item['display_number'], $item['name'], $item['category'], $item['unit'],
                $item['opening'], $item['received'], $item['picked'], $item['adjustments'],
                $item['closing'], $item['outdated'], $item['trashed'], $item['diverted'],
            ]);
        }
        $csv->insertOne([]);
        $csv->insertOne(['Period starting', 'Received', 'Picked', 'Adjustments', 'On hand (end)', 'Outdated', 'Trashed', 'Diverted']);
        foreach ($report['buckets'] as $b) {
            $csv->insertOne([$b['start'], $b['received'], $b['picked'], $b['adjustments'], $b['on_hand'],
                $b['outdated'], $b['trashed'], $b['diverted']]);
        }

        $filename = 'inventory-flow-'.now()->format('Y-m-d').'.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate(self::FILTER_RULES));
    }

    /** Fill in the defaults: the last 90 days, weekly. */
    private function resolve(array $filters): array
    {
        $to = CarbonImmutable::parse($filters['to'] ?? now()->toDateString())->startOfDay();
        $from = CarbonImmutable::parse($filters['from'] ?? $to->subDays(self::DEFAULT_DAYS - 1)->toDateString())->startOfDay();

        return [
            ...$filters,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'bucket' => $filters['bucket'] ?? 'week',
        ];
    }

    private function rangeError(array $filters): ?string
    {
        $f = $this->resolve($filters);
        $count = count($this->bucketStarts($f['from'], $f['to'], $f['bucket']));

        return $count > self::MAX_BUCKETS
            ? "That range is {$count} {$f['bucket']}s long; pick a shorter range or a bigger bucket (max ".self::MAX_BUCKETS.').'
            : null;
    }

    /** @return string[] Y-m-d start of every bucket overlapping [from, to] */
    private function bucketStarts(string $from, string $to, string $bucket): array
    {
        $cursor = $this->bucketStart(CarbonImmutable::parse($from), $bucket);
        $end = CarbonImmutable::parse($to);
        $starts = [];
        while ($cursor->lte($end) && count($starts) <= self::MAX_BUCKETS + 1) {
            $starts[] = $cursor->toDateString();
            $cursor = match ($bucket) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
            };
        }

        return $starts;
    }

    private function bucketStart(CarbonImmutable $date, string $bucket): CarbonImmutable
    {
        return match ($bucket) {
            'day' => $date->startOfDay(),
            'week' => $date->startOfWeek(Carbon::MONDAY),
            'month' => $date->startOfMonth(),
        };
    }

    /** SQL for the bucket a ledger row falls in (week = Monday, month = the 1st). */
    private function bucketSql(string $bucket): string
    {
        return match ($bucket) {
            'day' => 'DATE(item_ledgers.created_at)',
            'week' => 'DATE(item_ledgers.created_at - INTERVAL WEEKDAY(item_ledgers.created_at) DAY)',
            'month' => "DATE_FORMAT(item_ledgers.created_at, '%Y-%m-01')",
        };
    }

    /** Ledger rows joined to transaction type and item type, with the category/item filters. */
    private function ledger(array $filters)
    {
        return DB::table('item_ledgers')
            ->join('orderdonations', 'orderdonations.id', '=', 'item_ledgers.orderdonation_id')
            ->join('items', 'items.id', '=', 'item_ledgers.item_id')
            ->join('itemtypes', 'itemtypes.id', '=', 'items.itemtype_id')
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('itemtypes.category_id', $id))
            ->when($filters['itemtype_id'] ?? null, fn ($q, $id) => $q->where('itemtypes.id', $id));
    }

    public function buildReport(array $filters = []): array
    {
        $filters = $this->resolve($filters);
        $from = $filters['from'];
        $to = $filters['to'];

        // A row's effect on stock: usable additions less everything subtracted.
        $delta = "(CASE WHEN COALESCE(item_ledgers.disposition, 'usable') = 'usable' THEN COALESCE(item_ledgers.qty_added, 0) ELSE 0 END - COALESCE(item_ledgers.qty_subtracted, 0))";
        $isDonation = "orderdonations.type = 'donation'";
        $isOrder = "orderdonations.type = 'order'";
        $lost = fn (string $d) => "SUM(CASE WHEN {$isDonation} AND item_ledgers.disposition = '{$d}' THEN COALESCE(item_ledgers.qty_added, 0) ELSE 0 END)";

        $columns = "
            SUM(CASE WHEN {$isDonation} THEN {$delta} ELSE 0 END) AS received,
            SUM(CASE WHEN {$isOrder} THEN -{$delta} ELSE 0 END) AS picked,
            SUM(CASE WHEN NOT ({$isDonation}) AND NOT ({$isOrder}) THEN {$delta} ELSE 0 END) AS adjustments,
            {$lost('outdated')} AS outdated,
            {$lost('trashed')} AS trashed,
            {$lost('diverted')} AS diverted
        ";

        $inRange = fn ($q) => $q
            ->whereDate('item_ledgers.created_at', '>=', $from)
            ->whereDate('item_ledgers.created_at', '<=', $to);

        // Stock before the range starts, per item type.
        $opening = $this->ledger($filters)
            ->whereDate('item_ledgers.created_at', '<', $from)
            ->groupBy('items.itemtype_id')
            ->selectRaw("items.itemtype_id, SUM({$delta}) AS opening")
            ->pluck('opening', 'itemtype_id');

        $perBucket = $inRange($this->ledger($filters))
            ->groupByRaw($this->bucketSql($filters['bucket']))
            ->selectRaw($this->bucketSql($filters['bucket'])." AS bucket, {$columns}")
            ->get()
            ->keyBy('bucket');

        $perItem = $inRange($this->ledger($filters))
            ->groupBy('items.itemtype_id')
            ->selectRaw("items.itemtype_id, {$columns}")
            ->get()
            ->keyBy('itemtype_id');

        $byReason = $inRange($this->ledger($filters))
            ->where('orderdonations.type', 'adjustment')
            ->groupBy('orderdonations.adjustment_reason')
            ->selectRaw("
                orderdonations.adjustment_reason AS reason,
                SUM(CASE WHEN {$delta} > 0 THEN {$delta} ELSE 0 END) AS added,
                SUM(CASE WHEN {$delta} < 0 THEN -{$delta} ELSE 0 END) AS removed
            ")
            ->get();

        // Walk every bucket (including empty ones) so the chart is continuous.
        $running = (int) $opening->sum();
        $openingTotal = $running;
        $buckets = collect($this->bucketStarts($from, $to, $filters['bucket']))
            ->map(function (string $start) use ($perBucket, &$running) {
                $r = $perBucket->get($start);
                $row = [
                    'start' => $start,
                    'received' => (int) ($r->received ?? 0),
                    'picked' => (int) ($r->picked ?? 0),
                    'adjustments' => (int) ($r->adjustments ?? 0),
                    'outdated' => (int) ($r->outdated ?? 0),
                    'trashed' => (int) ($r->trashed ?? 0),
                    'diverted' => (int) ($r->diverted ?? 0),
                ];
                $running += $row['received'] - $row['picked'] + $row['adjustments'];

                return [...$row, 'on_hand' => $running];
            })
            ->values();

        $typeIds = $opening->keys()->merge($perItem->keys())->unique();
        $itemTypes = ItemType::with(['unit', 'category'])->whereIn('id', $typeIds)->get()->keyBy('id');

        $items = $typeIds
            ->map(function ($id) use ($opening, $perItem, $itemTypes) {
                $type = $itemTypes->get($id);
                $r = $perItem->get($id);
                $row = [
                    'itemtype_id' => (int) $id,
                    'display_number' => $type?->display_number,
                    'name' => $type?->name ?? '(deleted item type)',
                    'category' => $type?->category?->name,
                    'unit' => $type?->unit?->abbreviation ?? $type?->unit?->name,
                    'opening' => (int) ($opening[$id] ?? 0),
                    'received' => (int) ($r->received ?? 0),
                    'picked' => (int) ($r->picked ?? 0),
                    'adjustments' => (int) ($r->adjustments ?? 0),
                    'outdated' => (int) ($r->outdated ?? 0),
                    'trashed' => (int) ($r->trashed ?? 0),
                    'diverted' => (int) ($r->diverted ?? 0),
                ];

                return [...$row, 'closing' => $row['opening'] + $row['received'] - $row['picked'] + $row['adjustments']];
            })
            ->filter(fn ($i) => $i['opening'] || $i['received'] || $i['picked'] || $i['adjustments']
                || $i['outdated'] || $i['trashed'] || $i['diverted'])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $sum = fn (string $k) => (int) $items->sum($k);

        return [
            'filters' => (object) $filters,
            'buckets' => $buckets,
            'items' => $items,
            'adjustment_reasons' => $byReason->map(fn ($r) => [
                'reason' => $r->reason,
                'label' => PalletStockController::ADJUSTMENT_REASONS[$r->reason] ?? ($r->reason ?: 'Unspecified'),
                'added' => (int) $r->added,
                'removed' => (int) $r->removed,
            ])->values(),
            'totals' => [
                'opening' => $openingTotal,
                'received' => $sum('received'),
                'picked' => $sum('picked'),
                'adjustments' => $sum('adjustments'),
                'closing' => $buckets->last()['on_hand'] ?? $openingTotal,
                'outdated' => $sum('outdated'),
                'trashed' => $sum('trashed'),
                'diverted' => $sum('diverted'),
            ],
        ];
    }
}
