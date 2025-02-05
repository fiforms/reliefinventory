<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\ItemType;

class ItemTypeController extends Controller
{
    private const validation = [
        'number' => 'required|string|max:255|unique:itemtypes,number',
        'category_id' => 'required|exists:categories,id',
        'unit_id' => 'required|exists:units,id',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'active' => 'required|boolean',
    ];

    // Display a listing of item types
    public function index()
    {
        $itemTypes = ItemType::with(['category', 'unit'])->get();

        $templates = [
            '_default' => [
                'number' => '',
                'category_id' => null,
                'unit_id' => null,
                'name' => '',
                'description' => '',
                'active' => 1,
            ],
        ];

        return response()->json([
            'records' => $itemTypes,
            'templates' => $templates,
        ]);
    }

    // Store a newly created item type in storage
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), self::validation);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $itemType = ItemType::create($request->all());

        return response()->json($itemType, 201);
    }

    // Update the specified item type in storage
    public function update(Request $request, $id)
    {
        $itemType = ItemType::findOrFail($id);

        $validator = Validator::make($request->all(), self::validation);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $itemType->update($request->all());

        return response()->json($itemType);
    }

    // Remove the specified item type from storage
    public function destroy($id)
    {
        $itemType = ItemType::findOrFail($id);
        $itemType->delete();

        return response()->json(['message' => 'Item Type deleted successfully']);
    }
}
