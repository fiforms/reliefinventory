<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Location;

class LocationController extends Controller
{
    private const validation = [
        'PullSequence' => 'required|integer',
        'Route' => 'required|string|max:255',
        'Block' => 'required|string|max:255',
        'Aisle' => 'required|string|max:255',
        'Lane' => 'required|string|max:255',
        'Stack' => 'required|string|max:255',
        'use_id' => 'nullable|exists:uses,id',
        'code' => 'required|string|unique:locations,code',
        'status' => 'required|in:active,archived',
    ];

    /**
     * Retrieve all locations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $locations = Location::all();
        $templates = [
            '_default' => [
                'PullSequence' => null,
                'Route' => '',
                'Block' => '',
                'Aisle' => '',
                'Lane' => '',
                'Stack' => '',
                'use_id' => null,
                'code' => '',
                'status' => 'active',
            ]
        ];

        return response()->json([
            'records' => $locations,
            'templates' => $templates
        ]);
    }

    /**
     * Store a new location.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        $location = Location::create($data);

        return response()->json([
            'message' => 'Location created successfully.',
            'record' => $location
        ], 201);
    }

    /**
     * Update an existing location.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $location = Location::findOrFail($id);

        $data = $request->validate(self::validation + [
            'code' => "required|string|unique:locations,code,$id"
        ]);

        $location->update($data);

        return response()->json([
            'message' => 'Location updated successfully.',
            'record' => $location
        ], 200);
    }

    /**
     * Delete an existing location.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $location = Location::findOrFail($id);
            $location->delete();

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting location: ' . $e->getMessage()
            ], 500);
        }
    }
}
