<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\PalletStockAssignment;
use App\Services\PalletStock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;

/**
 * Sorting-area reconciliation / end-of-day check: what sorters produced (usable goods sorted) against what has been put on
 * a warehouse pallet, and what is therefore still sitting in the sorting area now — on-hand not on any pallet. "Still in
 * the sorting area" is always right now, not as of the end of the chosen range, and includes anything older than the range
 * (and stock from before pallet tracking), which is why each row also says how long it has been waiting.
 */
class SortingAreaReportController extends Controller
{
    public const FILTER_RULES = [
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
    ];

    public function index(Request $request)
    {
        return response()->json($this->buildReport($request->validate(self::FILTER_RULES)));
    }

    public function csv(Request $request)
    {
        $report = $this->buildReport($request->validate(self::FILTER_RULES));

        $csv = Writer::fromString('');
        $csv->insertOne(['Item #', 'Item', 'Sorted', 'Put on pallets', 'Still in sorting area', 'Waiting since']);
        foreach ($report['items'] as $r) {
            $csv->insertOne([$r['display_number'], $r['name'], $r['sorted'], $r['moved'], $r['in_area'], $r['waiting_since']]);
        }
        $csv->insertOne(['', 'Total', $report['totals']['sorted'], $report['totals']['moved'], $report['totals']['in_area'], '']);

        $name = 'sorting-area-'.$report['filters']['from'].($report['filters']['from'] === $report['filters']['to'] ? '' : '_to_'.$report['filters']['to']).'.csv';

        return response($csv->toString(), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** @param array{from?: ?string, to?: ?string} $filters  both default to today */
    public function buildReport(array $filters): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? $from->toDateString())->endOfDay();

        $sorted = ItemLedger::query()->whereBetween('created_at', [$from, $to])
            ->whereRaw("COALESCE(disposition, 'usable') = 'usable'")->where('qty_added', '>', 0)
            ->groupBy('item_id')->selectRaw('item_id, SUM(qty_added) as qty')->toBase()->pluck('qty', 'item_id');

        $moved = PalletStockAssignment::query()->whereBetween('created_at', [$from, $to])
            ->groupBy('item_id')->selectRaw('item_id, SUM(qty) as qty')->toBase()->pluck('qty', 'item_id');

        // Still in the sorting area now: on-hand minus everything on pallets.
        $onHand = DB::table('item_ledgers')->groupBy('item_id')
            ->selectRaw("item_id, SUM(CASE WHEN COALESCE(disposition, 'usable') = 'usable' THEN COALESCE(qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(qty_subtracted, 0)) as qty")->pluck('qty', 'item_id');
        $onPallets = PalletStock::quantities()->groupBy('item_id')->map(fn ($rows) => (int) $rows->sum('qty'));
        $inArea = $onHand->map(fn ($qty, $id) => (int) $qty - ($onPallets[$id] ?? 0))->filter(fn ($qty) => $qty > 0);

        $ids = $sorted->keys()->merge($moved->keys())->merge($inArea->keys())->unique()->values();
        $items = Item::with('itemtype')->whereIn('id', $ids)->get()->keyBy('id');

        $rows = $ids->map(fn ($id) => [
            'item_id' => (int) $id,
            'display_number' => $items[$id]->itemtype?->display_number,
            'name' => $items[$id]->description ?: $items[$id]->itemtype?->name,
            'sorted' => (int) ($sorted[$id] ?? 0),
            'moved' => (int) ($moved[$id] ?? 0),
            'in_area' => (int) ($inArea[$id] ?? 0),
            'waiting_since' => isset($inArea[$id]) ? $this->waitingSince((int) $id, (int) $inArea[$id]) : null,
        ])->sortBy([['in_area', 'desc'], ['name', 'asc']])->values();

        return [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'items' => $rows,
            'totals' => ['sorted' => $rows->sum('sorted'), 'moved' => $rows->sum('moved'), 'in_area' => $rows->sum('in_area')],
        ];
    }

    /**
     * Date the oldest unit still in the sorting area was sorted: the sorting area holds the most recently sorted stock
     * (oldest is shipped/palletized first), so walk the usable additions newest-first until the quantity is covered.
     */
    private function waitingSince(int $itemId, int $qty): ?string
    {
        $remaining = $qty;
        $date = null;
        $rows = ItemLedger::where('item_id', $itemId)->where('qty_added', '>', 0)->whereRaw("COALESCE(disposition, 'usable') = 'usable'")
            ->orderByDesc('created_at')->orderByDesc('id')->get(['qty_added', 'created_at']);
        foreach ($rows as $row) {
            $date = $row->created_at;
            $remaining -= (int) $row->qty_added;
            if ($remaining <= 0) {
                break;
            }
        }

        return $date?->toDateString();
    }
}
