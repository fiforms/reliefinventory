<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    private function validationRules(?int $ignoreId = null): array
    {
        return [
            'abbreviation' => ['required', 'string', Rule::unique('units', 'abbreviation')->ignore($ignoreId)],
            'name' => ['required', 'string', Rule::unique('units', 'name')->ignore($ignoreId)],
            'type' => 'required|in:weight,volume,each,other',
        ];
    }

    /**
     * Retrieve all units.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $units = Unit::all();

        return response()->json([
            'records' => $units,
            'templates' => [
                '_default' => [
                    'abbreviation' => '',
                    'name' => '',
                    'type' => 'each',
                ],
            ],
        ]);
    }

    /**
     * Store a new unit.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate($this->validationRules());
        Unit::create($data);

        return response()->json([
            'message' => 'Unit created successfully.',
        ], 201);
    }

    /**
     * Update an existing unit.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);
        $data = $request->validate($this->validationRules($unit->id));
        $unit->update($data);

        return response()->json([
            'message' => 'Unit updated successfully.',
        ], 200);
    }

    /**
     * Delete a unit.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $unit = Unit::findOrFail($id);
        $unit->delete();

        return response()->json([
            'message' => 'Unit deleted successfully.',
        ], 200);
    }
}
