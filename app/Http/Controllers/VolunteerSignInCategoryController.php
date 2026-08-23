<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\VolunteerSignInCategory;
use Illuminate\Http\Request;

/**
 * Minimal index+store controller for the volunteer_sign_in_categories
 * lookup table (the kiosk's "Other" category list) — same quick-add
 * pattern as PersonCategoryController, gated by operate-volunteer-kiosk
 * since it's a tightly-coupled sub-resource of the kiosk.
 */
class VolunteerSignInCategoryController extends Controller
{
    private const VALIDATION_RULES = [
        'name' => 'required|string|max:255|unique:volunteer_sign_in_categories,name',
    ];

    public function index()
    {
        return response()->json([
            'records' => VolunteerSignInCategory::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $category = VolunteerSignInCategory::create($data);

        return response()->json([
            'message' => 'Category created successfully.',
            'record' => $category,
        ], 201);
    }
}
