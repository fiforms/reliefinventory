<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
{
    /**
     * Get a list of warehouses.
     */
    public function index()
    {
        $warehouses = Warehouse::with(['county', 'manager'])->get();
        return response()->json([
            'records' => $warehouses,
            'templates' => [
                '_default' => [
                    'name' => '',
                    'address' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => '',
                    'county_id' => null,
                    'manager_id' => null,
                ]
            ]
        ]);
    }

    /**
     * Store a new warehouse.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:warehouses,name',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:2',
            'zip' => 'required|string|max:10',
            'county_id' => 'nullable|exists:counties,id',
            'manager_id' => 'nullable|exists:people,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $warehouse = Warehouse::create($request->all());

        return response()->json(['message' => 'Warehouse created successfully.'], 201);
    }

    /**
     * Update a warehouse.
     */
    public function update(Request $request, $id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:warehouses,name,' . $id,
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:2',
            'zip' => 'required|string|max:10',
            'county_id' => 'nullable|exists:counties,id',
            'manager_id' => 'nullable|exists:people,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $warehouse->update($request->all());

        return response()->json(['message' => 'Warehouse updated successfully.'], 200);
    }

    /**
     * Delete a warehouse.
     */
    public function destroy($id)
    {
        try {
            $warehouse = Warehouse::findOrFail($id);
            $warehouse->delete();

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting warehouse: ' . $e->getMessage()
            ], 500);
        }
    }
}
