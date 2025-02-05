<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Item;

class ItemController extends Controller
{
    
    private const validation = [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string|max:1000',
        'category' => 'required|string|max:255',
        'quantity' => 'required|integer|min:0',
        'unit' => 'required|string|max:255',
        'location' => 'required|string|max:255',
    ];
    
    // Display a listing of items
    public function index()
    {
        $items = Item::all()->map(function ($item) {
            $item->name = $item->item_number.' '.$item->description;
            return $item;
        });
        $templates = ['_default' => [
            'name' => '',
            'description' => '',
            'category' => '',
            'quantity' => 0,
            'unit' => '',
            'location' => '',
        ]];

        return response()->json([
            'records' => $items,
            'templates' => $templates
        ]);
    }

    // Store a newly created item in storage
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), self::validation);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = Item::create($request->all());

        return response()->json($item, 201);
    }

    // Update the specified item in storage
    public function update(Request $request, $id)
    {
        $item = Item::findOrFail($id);

        $validator = Validator::make($request->all(), self::validation);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item->update($request->all());

        return response()->json($item);
    }

    // Remove the specified item from storage
    public function destroy($id)
    {
        $item = Item::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Item deleted successfully']);
    }
}
