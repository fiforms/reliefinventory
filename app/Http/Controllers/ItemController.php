<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\PackageType;

class ItemController extends Controller
{

    /**
     * Compose the display name used by search/combo controls:
     * item type number + item description.
     */
    private static function displayName(Item $item): string
    {
        $number = $item->itemtype->number ?? '';
        return trim($number . ' ' . ($item->description ?? ''));
    }

    // Display a listing of items
    public function index()
    {
        $items = Item::with('itemtype')->get()->map(function ($item) {
            $item->name = self::displayName($item);
            return $item;
        });
        $templates = ['_default' => []];

        return response()->json([
            'records' => $items,
            'templates' => $templates
        ]);
    }

    /**
     * Quick-add an item under an existing item type. Used from the sorting
     * page when goods arrive that are not yet in the catalog; package/size
     * details default sensibly and can be refined later on the Item Entry
     * page.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'itemtype_id' => 'required|exists:itemtypes,id',
            'description' => 'required|string|max:255',
            'upc' => 'nullable|string|max:50|unique:items,upc',
            'packagetypes_id' => 'nullable|exists:packagetypes,id',
            'pluscode' => 'nullable|string|max:4',
            'size' => 'nullable|numeric',
            'case_qty' => 'nullable|integer',
        ]);

        $item = Item::create(array_merge([
            'packagetypes_id' => PackageType::min('id'),
            'pluscode' => '0000',
            'size' => 1.0,
            'active' => true,
        ], array_filter($data, fn ($value) => $value !== null)));

        $item->load('itemtype');
        $item->name = self::displayName($item);

        return response()->json(['record' => $item], 201);
    }

    /**
     * Update an existing item.
     */
    public function update(Request $request, $id)
    {
        $item = Item::findOrFail($id);

        $data = $request->validate([
            'itemtype_id' => 'required|exists:itemtypes,id',
            'description' => 'required|string|max:255',
            'upc' => 'nullable|string|max:50|unique:items,upc,' . $id,
            'packagetypes_id' => 'nullable|exists:packagetypes,id',
            'pluscode' => 'nullable|string|max:4',
            'size' => 'nullable|numeric',
            'case_qty' => 'nullable|integer',
            'active' => 'nullable|boolean',
        ]);

        $item->update($data);
        $item->load('itemtype');
        $item->name = self::displayName($item);

        return response()->json(['record' => $item], 200);
    }

    /**
     * Remove the specified item.
     */
    public function destroy($id)
    {
        $item = Item::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Item deleted successfully.'], 200);
    }
}
