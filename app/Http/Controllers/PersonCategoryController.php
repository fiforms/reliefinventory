<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\PersonCategory;
use Illuminate\Http\Request;

/**
 * Minimal index+store controller for the person_categories lookup table —
 * no dedicated admin page or permission key. Managed inline via
 * People.vue's Category SearchSelect (allowcreate), the same quick-add
 * pattern Receiving.vue uses for donors. Gated by manage-people, since this
 * is a tightly-coupled sub-resource of People rather than its own concern.
 */
class PersonCategoryController extends Controller
{
    private const VALIDATION_RULES = [
        'name' => 'required|string|max:255|unique:person_categories,name',
    ];

    public function index()
    {
        return response()->json([
            'records' => PersonCategory::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $category = PersonCategory::create($data);

        return response()->json([
            'message' => 'Category created successfully.',
            'record' => $category,
        ], 201);
    }
}
