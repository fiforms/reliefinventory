<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Helpers\Gtin;
use App\Models\CartonCode;
use App\Services\Upc\UpcResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Barcode -> item lookup for sorting. Answers from our own catalog
 * (`items.upc`, the SKU rows on Setup > Edit Master Item List) and only on a
 * miss asks online for a *suggestion*. Nothing is written here: a sorter
 * confirms a new barcode by adding the SKU row through the normal item
 * quick-add (`POST /json/items`), so there is exactly one place a UPC lives.
 */
class UpcController extends Controller
{
    public function resolve(Request $request, UpcResolver $resolver)
    {
        $data = $request->validate(['code' => 'required|string|max:64']);

        // online=0: catalog only, no network wait (the screen then asks for a suggestion separately)
        $result = $resolver->resolve($data['code'], $request->boolean('online', true));

        return response()->json($result, $result['status'] === 'invalid' ? 422 : 200);
    }

    /** The slow, optional half: an online suggestion for a barcode resolve() reported as unknown. */
    public function suggest(Request $request, UpcResolver $resolver)
    {
        $data = $request->validate(['code' => 'required|string|max:64']);

        return response()->json($resolver->suggest($data['code']));
    }

    /**
     * Confirm what a carton barcode contains: the single-unit item row and how many
     * units one carton holds (typed directly, or packs x items per pack). Upserts by
     * code; scanning that carton from now on selects the item with the quantity filled in.
     */
    public function storeCarton(Request $request, UpcResolver $resolver)
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
            'item_id' => 'required|exists:items,id',
            'inner_packs' => 'nullable|integer|min:1|max:100000',
            'items_per_pack' => 'nullable|integer|min:1|max:100000',
            'qty_per_carton' => 'nullable|integer|min:1|max:1000000',
        ]);

        $code = Gtin::normalize($data['code']);
        if ($code === null || ! Gtin::isValid($code)) {
            throw ValidationException::withMessages(['code' => 'That barcode is not valid — please scan it again.']);
        }

        $qty = $data['qty_per_carton'] ?? (($data['inner_packs'] ?? 1) * ($data['items_per_pack'] ?? 1));
        if (empty($data['qty_per_carton']) && empty($data['inner_packs']) && empty($data['items_per_pack'])) {
            throw ValidationException::withMessages(['qty_per_carton' => 'Enter how many items are in one carton.']);
        }

        CartonCode::updateOrCreate(['code' => $code], [
            'item_id' => $data['item_id'],
            'qty_per_carton' => $qty,
            'inner_packs' => $data['inner_packs'] ?? null,
            'items_per_pack' => $data['items_per_pack'] ?? null,
            'person_id_user' => Auth::id(), // server-side, never from the request
        ]);

        return response()->json($resolver->resolve($code, false), 201);
    }

    /** Confirmed carton codes, newest first (bounded: history-style lists must be), for the review screen. */
    public function indexCartons()
    {
        $records = CartonCode::with(['item.itemtype', 'enteredBy'])->latest('id')->limit(500)->get()->map(function ($carton) {
            if ($carton->item) {
                $carton->item->name = ItemController::displayName($carton->item);
            }
            $carton->entered_by_name = $carton->enteredBy?->full_name;
            $carton->unsetRelation('enteredBy'); // just the name: never the whole person record

            return $carton;
        });

        return response()->json(['records' => $records, 'total' => CartonCode::count()]);
    }

    public function destroyCarton($id)
    {
        CartonCode::findOrFail($id)->delete();

        return response()->json(['message' => 'Carton code removed.']);
    }
}
