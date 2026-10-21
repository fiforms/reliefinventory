<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Upc;

use App\Helpers\Gtin;
use App\Http\Controllers\ItemController;
use App\Models\Item;

/**
 * Turns a scanned barcode into an item row (a SKU under an ACS item type)
 * using only our own catalog — `items.upc`, in any spelling (UPC-A, EAN-13,
 * GTIN-14) — and, on a miss, assembles what the add-item screen needs: the
 * barcode's kind (single unit or carton), a carton's likely single-unit item
 * and an online suggestion. Nothing here writes.
 *
 * A carton is simply another item row with its own UPC (package "Case",
 * Case Qty N), so "matched" reports `qty` = case_qty x size, the number of
 * single units one scan stands for; null for an ordinary item.
 */
class UpcResolver
{
    public function __construct(private UpcLookupService $lookup) {}

    public function resolve(string $scanned): array
    {
        $code = Gtin::normalize($scanned);
        if ($code === null) {
            return ['status' => 'invalid', 'message' => 'That does not look like a UPC/EAN barcode (8, 12, 13 or 14 digits).'];
        }

        if ($item = $this->find($code)) {
            return $this->matched($code, $item);
        }

        if (! Gtin::isValid($code)) {
            return ['status' => 'invalid', 'message' => 'The barcode check digit does not match — please scan it again.'];
        }

        $kind = Gtin::kind($code);
        $eachCode = $kind === 'carton' ? Gtin::guessEachCode($code) : null;

        // A carton whose single-unit code we already carry needs no online
        // help, only the person confirming it and entering the case quantity.
        $guessItem = $eachCode ? $this->find($eachCode) : null;
        if ($guessItem) {
            $guessItem->name = ItemController::displayName($guessItem);
        }

        $online = $guessItem
            ? ['status' => 'skipped']
            : $this->lookup->lookup(array_values(array_filter([$code, $eachCode])));

        return [
            'status' => 'unknown',
            'code' => $code,
            'kind' => $kind,
            'each_code' => $eachCode,
            'guess_item' => $guessItem,
            'online' => $online['status'],
            'suggestion' => $online['suggestion'] ?? null,
        ];
    }

    private function find(string $code): ?Item
    {
        return Item::with(['itemtype', 'packagetype'])->whereIn('upc', Gtin::variants($code))->first();
    }

    private function matched(string $code, Item $item): array
    {
        $item->name = ItemController::displayName($item);
        $isCase = ($item->case_qty ?? 0) > 1;

        return [
            'status' => 'matched',
            'code' => $code,
            'kind' => $isCase ? 'carton' : 'each',
            'qty' => $isCase ? (int) round($item->case_qty * ((float) ($item->size ?: 1))) : null,
            'item' => $item,
        ];
    }
}
