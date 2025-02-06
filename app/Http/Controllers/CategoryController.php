<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private const validation = [
        'name' => 'required|string|unique:categories,name',
    ];

    /**
     * Retrieve all categories.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $categories = Category::all();

        return response()->json([
            'records' => $categories,
            'templates' => [
                '_default' => [
                    'name' => '',
                ]
            ]
        ]);
    }

    /**
     * Store a new category.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        $category = Category::create($data);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    /**
     * Update an existing category.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $data = $request->validate(self::validation);

        $category->update($data);

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $category,
        ], 200);
    }

    /**
     * Delete a category.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting category: ' . $e->getMessage()
            ], 500);
        }
    }
}
