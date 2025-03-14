<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\ItemType;
use App\Helpers\UPCGenerator;


class ItemTypeController extends Controller
{
    private const validation = [
        'number' => 'required|string|max:255|unique:itemtypes,number',
        'category_id' => 'required|exists:categories,id',
        'unit_id' => 'required|exists:units,id',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'active' => 'required|boolean',
        'items' => 'nullable|array',
        'items.*.packagetypes_id' => 'required|exists:packagetypes,id',
        'items.*.pluscode' => 'required|string|max:4',
        'items.*.size' => 'nullable|numeric',
        'items.*.case_qty' => 'nullable|integer',
        'items.*.active' => 'required|boolean',
        'items.*.description' => 'nullable|string',
        'items.*.upc' => 'nullable|string|max:50',
        
    ];

    // Display a listing of item types
    public function index(String $mod = '')
    {
        $with = ['category','unit','items.packagetype'];
        if($mod == 'noitems') {
            $with = ['category','unit'];
        }
        $itemTypes = ItemType::with($with)->get();

        $templates = [
            '_default' => [
                'number' => '',
                'category_id' => null,
                'unit_id' => null,
                'name' => '',
                'description' => '',
                'active' => true,
                'items' => [],
            ],
            'items' => [
                'packagetype_id' => null,
                'pluscode' => '0000',
                'size' => null,
                'case_qty' => null,
                'active' => true,
                'description' => '',
                'upc' => null,
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
        $data = $request->all();
        
        // Ensure 'number' is numeric and format it correctly
        if (!ctype_digit($data['number']) || strlen($data['number']) > 5) {
            return response()->json(['errors' => ['number' => 'The number must be a 5-digit numeric code.']], 422);
        }
        
        // Pad number to 5 digits
        $data['number'] = str_pad($data['number'], 5, '0', STR_PAD_LEFT);
        
        $validator = Validator::make($data, self::validation);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $itemType = ItemType::create($data);
        
        // Generate the UPC using the helper class
        $upc = UPCGenerator::makeUPCFromItemNumber($data['number']);
        
        // Create a generic item linked to this ItemType
        $itemType->items()->create([
            'packagetypes_id' => 1, // Assuming default package type ID
            'pluscode' => '0000',
            'size' => 1.0,
            'case_qty' => 1,
            'active' => 1,
            'description' => $itemType->name . " GENERIC ITEM",
            'upc' => $upc,
        ]);
        
        return response()->json($itemType, 201);
    }
    
    // Update the specified item type in storage
    public function update(Request $request, $id)
    {
        $itemType = ItemType::findOrFail($id);
        $data = $request->all();
        
        // Ensure 'number' is numeric and format it correctly
        if (!ctype_digit($data['number']) || strlen($data['number']) > 5) {
            return response()->json(['errors' => ['number' => 'The number must be a 5-digit numeric code.']], 422);
        }
        
        // Pad number to 5 digits
        $data['number'] = str_pad($data['number'], 5, '0', STR_PAD_LEFT);
        
        $validationRules = self::validation;
        $validationRules['number'] = 'required|string|max:255|unique:itemtypes,number,' . $id;
        
        $validator = Validator::make($data, $validationRules);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $itemType->update($data);

        // Retrieve current item IDs from the database
        $existingItemIds = $itemType->items()->pluck('id')->toArray();
        
        // Extract incoming item IDs
        $updatedItemIds = collect($request->input('items', []))
        ->pluck('id')
        ->filter() // Remove nulls (new records won't have IDs)
        ->toArray();
        
        // Identify items that need to be deleted
        $deletedItemIds = array_diff($existingItemIds, $updatedItemIds);
        
        // Delete removed items
        if (!empty($deletedItemIds)) {
            $itemType->items()->whereIn('id', $deletedItemIds)->delete();
        }
        
        // Handle new and updated items
        foreach ($request->input('items', []) as $itemData) {
            if (!empty($itemData['id'])) {
                // Update existing item
                $existingItem = $itemType->items()->find($itemData['id']);
                if ($existingItem) {
                    $existingItem->update($itemData);
                }
            } else {
                // Create a new item
                $itemType->items()->create($itemData);
            }
        }
        
        return response()->json($itemType->load('items'));
        
    }

    // Remove the specified item type from storage
    public function destroy($id)
    {
        $itemType = ItemType::findOrFail($id);
        $itemType->delete();

        return response()->json(['message' => 'Item Type deleted successfully']);
    }
}
