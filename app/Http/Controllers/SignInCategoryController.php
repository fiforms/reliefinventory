<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskLocation;
use App\Models\SignInCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The sign_in_categories lookup table — the kiosk's "Other category"/
 * Guest-type list, scoped per kiosk_location_id. `index` (kiosk-access) is
 * the read the kiosk device itself uses, filtered to its own location;
 * `forLocation` (admin-system) is the equivalent read for the Kiosk
 * Settings page managing a specific location's list — kept as a separate
 * route/method rather than reusing `index` under both middleware groups,
 * since Laravel can't register the same URI+method twice.
 */
class SignInCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = SignInCategory::orderBy('name');

        if ($request->filled('kiosk_location_id')) {
            $query->where('kiosk_location_id', $request->integer('kiosk_location_id'));
        }

        return response()->json([
            'records' => $query->get(),
        ]);
    }

    public function forLocation(KioskLocation $kioskLocation)
    {
        return response()->json([
            'records' => $kioskLocation->signInCategories()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kiosk_location_id' => 'required|integer|exists:kiosk_locations,id',
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('sign_in_categories')->where('kiosk_location_id', $request->input('kiosk_location_id')),
            ],
        ]);

        $category = SignInCategory::create($data);

        return response()->json([
            'message' => 'Category created successfully.',
            'record' => $category,
        ], 201);
    }
}
