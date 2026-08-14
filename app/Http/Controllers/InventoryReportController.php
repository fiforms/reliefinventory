<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ItemType;
use Illuminate\Support\Facades\DB;

/**
 * Stock-on-hand rollup — the ledger (item_ledgers) never gets aggregated
 * anywhere else in the app, so this is the one place "what do we have"
 * gets answered. Staff-only: this reports exact quantities, which is fine
 * here (unlike any future customer-facing surface, which must never show
 * real numbers — see the order-intake design notes).
 *
 * "On hand" mirrors the rule sorting/order-intake already use: usable
 * additions minus subtractions. Outdated/trashed/diverted quantities never
 * counted as inventory in the first place, so they're reported separately
 * as operational context, not netted into on_hand.
 */
class InventoryReportController extends Controller
{
    public function index()
    {
        // Item-level rollup first (one itemtype can span several brands/
        // SKUs), then grouped up to itemtype in PHP — simpler than nested
        // SQL grouping and the row counts here are small.
        $itemRows = DB::table('items')
            ->leftJoin('item_ledgers', 'item_ledgers.item_id', '=', 'items.id')
            ->groupBy('items.id', 'items.itemtype_id', 'items.description', 'items.upc')
            ->selectRaw("
                items.id, items.itemtype_id, items.description, items.upc,
                SUM(CASE WHEN COALESCE(item_ledgers.disposition, 'usable') = 'usable'
                    THEN COALESCE(item_ledgers.qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(item_ledgers.qty_subtracted, 0)) AS on_hand,
                SUM(CASE WHEN item_ledgers.disposition = 'outdated' THEN item_ledgers.qty_added ELSE 0 END) AS outdated,
                SUM(CASE WHEN item_ledgers.disposition = 'trashed' THEN item_ledgers.qty_added ELSE 0 END) AS trashed,
                SUM(CASE WHEN item_ledgers.disposition = 'diverted' THEN item_ledgers.qty_added ELSE 0 END) AS diverted
            ")
            ->get()
            ->groupBy('itemtype_id');

        $itemTypes = ItemType::with('category', 'unit')->get();

        $records = $itemTypes->map(function (ItemType $itemType) use ($itemRows) {
            $items = $itemRows->get($itemType->id, collect())->map(fn ($row) => [
                'id' => $row->id,
                'description' => $row->description,
                'upc' => $row->upc,
                'on_hand' => (int) $row->on_hand,
                'outdated' => (int) $row->outdated,
                'trashed' => (int) $row->trashed,
                'diverted' => (int) $row->diverted,
            ])->values();

            return [
                'id' => $itemType->id,
                'display_number' => $itemType->display_number,
                'name' => $itemType->name,
                'status' => $itemType->status,
                'category' => $itemType->category?->name,
                'unit' => $itemType->unit?->abbreviation ?? $itemType->unit?->name,
                'on_hand' => (int) $items->sum('on_hand'),
                'outdated' => (int) $items->sum('outdated'),
                'trashed' => (int) $items->sum('trashed'),
                'diverted' => (int) $items->sum('diverted'),
                'items' => $items,
            ];
        })->sortBy([
            ['category', 'asc'],
            ['display_number', 'asc'],
        ])->values();

        return response()->json(['records' => $records]);
    }
}
