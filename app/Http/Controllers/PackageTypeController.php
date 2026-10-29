<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\PackageType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackageTypeController extends Controller
{
    /**
     * `unique:packagetypes,type` was stale — the 2025_02_05 migration
     * renamed the old single `type` column to `singular`/`plural` (and
     * moved the unique constraint to the pair of them), but this rule set
     * was never updated. Every store()/update() 500ed on "Unknown column
     * 'type'" as a result — the underlying cause behind feedback #43
     * ("package types is blocking adding a new item").
     */
    private static function validation($id = null): array
    {
        return [
            'singular' => ['required', 'string', 'max:255', Rule::unique('packagetypes', 'singular')->ignore($id)],
            'plural' => ['required', 'string', 'max:255', Rule::unique('packagetypes', 'plural')->ignore($id)],
        ];
    }

    /**
     * Retrieve all package types.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $packageTypes = PackageType::all();

        return response()->json([
            'records' => $packageTypes,
            'templates' => [
                '_default' => [
                    'singular' => '',
                    'plural' => '',
                ],
            ],
        ]);
    }

    /**
     * Store a new package type.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation());
        $packageType = PackageType::create($data);

        return response()->json([
            'message' => 'Package type created successfully.',
            'record' => $packageType,
        ], 201);
    }

    /**
     * Update an existing package type.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $packageType = PackageType::findOrFail($id);
        $data = $request->validate(self::validation($id));

        $packageType->update($data);

        return response()->json([
            'message' => 'Package type updated successfully.',
            'record' => $packageType,
        ], 200);
    }

    /**
     * Delete a package type.
     *
     * @param  int  $id
     * @return JsonResponse
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
