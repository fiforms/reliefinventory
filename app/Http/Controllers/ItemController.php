<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\PackageType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    /**
     * Compose the display name used by search/combo controls:
     * item type number + item description.
     */
    public static function displayName(Item $item): string
    {
        $number = $item->itemtype->display_number ?? '';

        return trim($number.' '.($item->description ?? ''));
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
            'templates' => $templates,
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
            'upc' => 'nullable|string|max:50|unique:items,upc,'.$id,
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

    /**
     * What moving an item to another ACS # would touch. Everything links to the
     * item row (item_ledgers, pallet stock, carton codes), not to a type, so
     * moving the item re-homes all of its history with it — this is the count
     * shown to the admin before they confirm.
     */
    public function moveImpact($id)
    {
        $item = Item::with('itemtype')->findOrFail($id);

        return response()->json([
            'item' => ['id' => $item->id, 'description' => $item->description, 'acs' => $item->itemtype?->display_number, 'itemtype' => $item->itemtype?->name],
            'ledger_rows' => ItemLedger::where('item_id', $item->id)->count(),
            // Fills tied to an order line whose requested type is the item's current type; after the move
            // they would sit under a different type than the line asked for.
            'order_fills' => ItemLedger::where('item_id', $item->id)->whereNotNull('order_line_id')->count(),
            'pallet_assignments' => DB::table('pallet_stock_assignments')->where('item_id', $item->id)->count(),
        ]);
    }

    /**
     * Move an item to a different ACS # (item type). Administrator-only (admin-system).
     * Retroactive by design: stock on hand, history and reports follow the item.
     */
    public function move(Request $request, $id)
    {
        $data = $request->validate(['itemtype_id' => 'required|exists:itemtypes,id']);

        $item = Item::findOrFail($id);
        $from = ItemType::find($item->itemtype_id);
        $to = ItemType::findOrFail($data['itemtype_id']);

        if ((int) $item->itemtype_id === (int) $to->id) {
            return response()->json(['message' => 'That item is already under '.$to->display_number.'.'], 422);
        }

        DB::transaction(fn () => $item->update(['itemtype_id' => $to->id]));

        logger()->info('Item moved to a new ACS #', [
            'item_id' => $item->id,
            'from' => $from?->display_number,
            'to' => $to->display_number,
            'by' => $request->user()->id,
        ]);

        $item->load('itemtype');
        $item->name = self::displayName($item);

        return response()->json(['record' => $item]);
    }
}
