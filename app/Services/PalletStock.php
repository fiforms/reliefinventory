<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\ItemLedger;
use App\Models\PalletStockAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is on each warehouse pallet. Nothing is stored as a running total: a pallet's quantity of an item is
 * assignments (stock put on it) plus usable ledger additions naming it, minus ledger subtractions naming it, so it
 * can never drift from the ledger. Stock not yet on any pallet ("unassigned") is on-hand minus everything on pallets.
 */
class PalletStock
{
    /**
     * @param  int[]|null  $palletIds  limit to these pallets
     * @param  int[]|null  $itemIds  limit to these items
     * @return Collection<int, array{pallet_id:int,item_id:int,qty:int}>
     */
    public static function quantities(?array $palletIds = null, ?array $itemIds = null): Collection
    {
        $ledger = ItemLedger::query()->whereNotNull('stock_pallet_id')
            ->when($palletIds !== null, fn ($q) => $q->whereIn('stock_pallet_id', $palletIds))
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->groupBy('stock_pallet_id', 'item_id')
            ->selectRaw("stock_pallet_id as pallet_id, item_id,
                SUM(CASE WHEN COALESCE(disposition, 'usable') = 'usable' THEN COALESCE(qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(qty_subtracted, 0)) as qty")
            ->toBase()->get();

        $assigned = PalletStockAssignment::query()
            ->when($palletIds !== null, fn ($q) => $q->whereIn('pallet_id', $palletIds))
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->groupBy('pallet_id', 'item_id')
            ->selectRaw('pallet_id, item_id, SUM(qty) as qty')
            ->toBase()->get();

        $merged = [];
        foreach ($ledger->concat($assigned) as $row) {
            $key = $row->pallet_id.':'.$row->item_id;
            $merged[$key] ??= ['pallet_id' => (int) $row->pallet_id, 'item_id' => (int) $row->item_id, 'qty' => 0];
            $merged[$key]['qty'] += (int) $row->qty;
        }

        return collect(array_values($merged));
    }

    /** Total of one item across every pallet. */
    public static function onPallets(int $itemId): int
    {
        return (int) self::quantities(null, [$itemId])->sum('qty');
    }

    /** On-hand of one item (usable additions − subtractions), the ledger's pooled figure. */
    public static function onHand(int $itemId): int
    {
        return (int) (DB::table('item_ledgers')->where('item_id', $itemId)
            ->selectRaw("SUM(CASE WHEN COALESCE(disposition, 'usable') = 'usable' THEN COALESCE(qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(qty_subtracted, 0)) as n")->value('n') ?? 0);
    }

    /** On-hand not yet assigned to any pallet. */
    public static function unassigned(int $itemId): int
    {
        return self::onHand($itemId) - self::onPallets($itemId);
    }

    public static function palletTotal(int $palletId): int
    {
        return (int) self::quantities([$palletId])->sum('qty');
    }
}
