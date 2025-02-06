<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\PackageType;
use Illuminate\Http\Request;

class PackageTypeController extends Controller
{
    private const VALIDATION_RULES = [
        'type' => 'required|string|unique:packagetypes,type|max:255',
    ];

    /**
     * Retrieve all package types.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $packageTypes = PackageType::all();

        return response()->json([
            'records' => $packageTypes,
            'templates' => [
                '_default' => [
                    'type' => '',
                ],
            ],
        ]);
    }

    /**
     * Store a new package type.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $packageType = PackageType::create($data);

        return response()->json([
            'message' => 'Package type created successfully.',
            'record' => $packageType,
        ], 201);
    }

    /**
     * Update an existing package type.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $packageType = PackageType::findOrFail($id);
        $data = $request->validate([
            'type' => 'required|string|max:255|unique:packagetypes,type,' . $packageType->id,
        ]);

        $packageType->update($data);

        return response()->json([
            'message' => 'Package type updated successfully.',
            'record' => $packageType,
        ], 200);
    }

    /**
     * Delete a package type.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $packageType = PackageType::findOrFail($id);
        $packageType->delete();

        return response()->json([
            'message' => 'Package type deleted successfully.',
            'success' => true,
        ], 200);
    }
}
