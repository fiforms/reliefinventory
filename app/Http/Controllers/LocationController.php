<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    // Only code, use, and status are required; the physical coordinates and
    // pull sequence are optional detail.
    private const validation = [
        'PullSequence' => 'nullable|integer',
        'Route' => 'nullable|string|max:255',
        'Block' => 'nullable|string|max:255',
        'Aisle' => 'nullable|string|max:255',
        'Lane' => 'nullable|string|max:255',
        'Stack' => 'nullable|string|max:255',
        'use_id' => 'required|exists:uses,id',
        'code' => 'required|string|unique:locations,code',
        'status' => 'required|in:active,archived',
    ];

    /**
     * Retrieve all locations.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $locations = Location::orderByRaw('PullSequence is null')->orderBy('PullSequence')->orderBy('code')->get();
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
            ],
        ];

        return response()->json([
            'records' => $locations,
            'templates' => $templates,
        ]);
    }

    /**
     * Store a new location.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        // locations.warehouse_id is NOT NULL; the app runs a single warehouse, so new locations go in it.
        $data['warehouse_id'] = (Warehouse::first() ?? Warehouse::create(['name' => 'Main Warehouse']))->id;
        $location = Location::create($data);

        return response()->json([
            'message' => 'Location created successfully.',
            'record' => $location,
        ], 201);
    }

    /**
     * Update an existing location.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $location = Location::findOrFail($id);

        $data = $request->validate(array_merge(self::validation, [
            'code' => "required|string|unique:locations,code,$id",
        ]));

        $location->update($data);

        return response()->json([
            'message' => 'Location updated successfully.',
            'record' => $location,
        ], 200);
    }

    /**
     * Delete an existing location.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    /**
     * Save the pick path: the given location ids, in walking order, become PullSequence 10, 20, 30… (gaps leave room
     * for hand-edits). Active locations missing from the list keep their place relative to each other after the listed
     * ones, so a stale editor can never silently drop a location off the path.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|distinct|exists:locations,id']);

        DB::transaction(function () use ($data) {
            $rest = Location::whereNotIn('id', $data['ids'])->orderByRaw('PullSequence is null')->orderBy('PullSequence')->orderBy('code')->pluck('id')->all();
            foreach (array_merge($data['ids'], $rest) as $i => $id) {
                Location::whereKey($id)->update(['PullSequence' => ($i + 1) * 10]);
            }
        });

        return response()->json(['message' => 'Pick path saved.']);
    }

    public function destroy($id)
    {
        try {
            $location = Location::findOrFail($id);
            $location->delete();

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting location: '.$e->getMessage(),
            ], 500);
        }
    }
}
