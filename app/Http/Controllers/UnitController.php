<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UnitController extends Controller
{
    private const VALIDATION_RULES = [
        'abbreviation' => 'required|string|unique:units,abbreviation',
        'name' => 'required|string|unique:units,name',
        'type' => 'required|in:weight,volume,each,other',
    ];

    /**
     * Retrieve all units.
     *
     * @return \Illuminate\Http\JsonResponse
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
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        Unit::create($data);

        return response()->json([
            'message' => 'Unit created successfully.',
        ], 201);
    }

    /**
     * Update an existing unit.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);
        $data = $request->validate(self::VALIDATION_RULES);
        $unit->update($data);

        return response()->json([
            'message' => 'Unit updated successfully.',
        ], 200);
    }

    /**
     * Delete a unit.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
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
