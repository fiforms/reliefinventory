<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\TrainingProduct;
use App\Services\TrainingPreparer;
use App\Services\Upc\UpcResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Setup > Training Warehouse > Scan Training Products (`manage-training`): the list of real goods for the class, built by
 * scanning each unit. A barcode is looked up the way Sorting does (UpcResolver, catalog only — the screen asks for an
 * online suggestion separately); a barcode the catalog doesn't know is added through the shared Add New Item box,
 * which then calls store(). This list is not stock: Prepare Training (App\Services\TrainingPreparer) reads it.
 */
class TrainingProductController extends Controller
{
    public function index()
    {
        return response()->json($this->payload());
    }

    /** One scan: count one unit (a known case barcode counts all the units in it), or say the barcode is unknown. */
    public function scan(Request $request, UpcResolver $resolver)
    {
        $data = $request->validate(['code' => 'required|string|max:64']);
        $result = $resolver->resolve($data['code'], false);

        if ($result['status'] === 'invalid') {
            return response()->json(['message' => $result['message']], 422);
        }
        if ($result['status'] !== 'matched') {
            return response()->json(['scan' => $result] + $this->payload());
        }

        $product = $this->add($result['item']->id, (int) ($result['qty'] ?: 1));

        return response()->json(['scan' => $result, 'product_id' => $product->id] + $this->payload());
    }

    /** Count units of an item chosen by hand, or just added through Add New Item. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'item_id' => 'required|integer|exists:items,id',
            'qty' => 'nullable|integer|min:1|max:10000',
        ]);
        $product = $this->add($data['item_id'], $data['qty'] ?? 1);

        return response()->json(['product_id' => $product->id] + $this->payload(), 201);
    }

    /** Correct a count; 0 takes the product off the list. */
    public function update(Request $request, int $id)
    {
        $data = $request->validate(['qty' => 'required|integer|min:0|max:10000']);
        $product = TrainingProduct::findOrFail($id);
        $data['qty'] === 0 ? $product->delete() : $product->update(['qty' => $data['qty']]);

        return response()->json($this->payload());
    }

    public function destroy(int $id)
    {
        TrainingProduct::findOrFail($id)->delete();

        return response()->json($this->payload());
    }

    /** Start the list over (the items stay in the Master Item List). */
    public function clear()
    {
        TrainingProduct::query()->delete();

        return response()->json($this->payload());
    }

    private function add(int $itemId, int $qty): TrainingProduct
    {
        $product = TrainingProduct::firstOrNew(['item_id' => $itemId]);
        $product->qty = ($product->qty ?? 0) + $qty;
        $product->person_id_user = Auth::id(); // server-side, never from the request
        $product->save();

        return $product;
    }

    private function payload(): array
    {
        $records = TrainingProduct::with('item.itemtype')->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->map(fn (TrainingProduct $p) => [
                'id' => $p->id,
                'item_id' => $p->item_id,
                'name' => trim((string) $p->item?->description) ?: ($p->item?->itemtype?->name ?? 'Item #'.$p->item_id),
                'number' => $p->item?->itemtype?->display_number,
                'type' => $p->item?->itemtype?->name,
                'upc' => $p->item?->upc,
                'qty' => $p->qty,
                'updated_at' => $p->updated_at,
            ])->values();

        return [
            'records' => $records,
            'units' => (int) $records->sum('qty'),
            'distinct' => $records->count(),
            'plan' => TrainingPreparer::plan(),
        ];
    }
}
