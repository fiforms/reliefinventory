<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\Upc\UpcResolver;
use Illuminate\Http\Request;

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

        $result = $resolver->resolve($data['code']);

        return response()->json($result, $result['status'] === 'invalid' ? 422 : 200);
    }
}
