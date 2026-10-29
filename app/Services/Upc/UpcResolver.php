<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Upc;

use App\Helpers\Gtin;
use App\Http\Controllers\ItemController;
use App\Models\CartonCode;
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

    /**
     * @param  bool  $online  false = answer from our own catalog only and report
     *                        the online suggestion as 'pending' (fetch it with suggest()),
     *                        so the screen can react at once instead of waiting on the network
     */
    public function resolve(string $scanned, bool $online = true): array
    {
        $code = Gtin::normalize($scanned);
        if ($code === null) {
            return ['status' => 'invalid', 'message' => 'That does not look like a UPC/EAN barcode (8, 12, 13 or 14 digits).'];
        }

        // A confirmed carton: report the single-unit row inside it and how many units a carton holds.
        $carton = CartonCode::with(['item.itemtype', 'item.packagetype'])->where('code', $code)->first();
        if ($carton && $carton->item) {
            $item = $carton->item;
            $item->name = ItemController::displayName($item);

            return [
                'status' => 'matched', 'code' => $code, 'kind' => 'carton', 'qty' => $carton->qty_per_carton, 'item' => $item,
                'inner_packs' => $carton->inner_packs, 'items_per_pack' => $carton->items_per_pack,
            ];
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

        $lookup = match (true) {
            $guessItem !== null => ['status' => 'skipped'],
            ! $online => ['status' => 'pending'],
            default => $this->lookup->lookup(array_values(array_filter([$code, $eachCode]))),
        };

        return [
            'status' => 'unknown',
            'code' => $code,
            'kind' => $kind,
            'each_code' => $eachCode,
            'guess_item' => $guessItem,
            // not the exact single-unit code, but other items from the same manufacturer (GS1 company prefix)
            'candidates' => ($kind === 'carton' && ! $guessItem) ? $this->sameManufacturer($code) : [],
            'online' => $lookup['status'],
            'suggestion' => $lookup['suggestion'] ?? null,
        ];
    }

    /** The online half of resolve(): a suggestion for an unknown, valid barcode (never throws). */
    public function suggest(string $scanned): array
    {
        $result = $this->resolve($scanned, true);

        return $result['status'] === 'unknown'
            ? ['online' => $result['online'], 'suggestion' => $result['suggestion']]
            : ['online' => 'skipped', 'suggestion' => null];
    }

    /**
     * Items whose UPC starts with the carton's manufacturer prefix. A carton's
     * item number often differs from the single item's (it did for
     * 10842515008167 vs 842515006715), but both start with the same company
     * prefix, so this narrows "what is in it?" to a few likely rows.
     *
     * @return array<int, array{id:int,name:string,upc:string}>
     */
    private function sameManufacturer(string $cartonCode): array
    {
        // the item-level GTIN-14 this carton would wrap starts '0' + the carton's 12-digit body
        $prefix = '0'.substr($cartonCode, 1, 7); // '0' + first 7 body digits (6-digit UPC company prefix + number system)
        $patterns = array_filter([
            substr($prefix, 2).'%' => $prefix[0] === '0' && $prefix[1] === '0',
            substr($prefix, 1).'%' => $prefix[0] === '0',
            $prefix.'%' => true,
        ]);

        $items = Item::with('itemtype')->whereNotNull('upc')
            ->where(function ($query) use ($patterns) {
                foreach (array_keys($patterns) as $pattern) {
                    $query->orWhere('upc', 'like', $pattern);
                }
            })->limit(10)->get();

        return $items->map(fn (Item $item) => [
            'id' => $item->id,
            'name' => ItemController::displayName($item),
            'upc' => $item->upc,
        ])->all();
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
