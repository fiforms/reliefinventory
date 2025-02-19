<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Role;

class RoleController extends Controller
{
    private const validation = [
        'name' => 'required|string|max:255|unique:roles,name',
        'description' => 'nullable|string',
    ];

    /**
     * Retrieve all roles.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $roles = Role::all();
        $templates = [
            '_default' => [
                'name' => '',
                'description' => '',
            ],
        ];

        return response()->json([
            'records' => $roles,
            'templates' => $templates,
        ]);
    }

    /**
     * Store a new role.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        Role::create($data);

        return response()->json([
            'message' => 'Role created successfully.',
        ], 201);
    }

    /**
     * Update an existing role.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $id,
            'description' => 'nullable|string',
        ]);

        $role->update($data);

        return response()->json([
            'message' => 'Role updated successfully.',
        ], 200);
    }

    /**
     * Delete a role.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $role = Role::findOrFail($id);
            $role->delete();

            return response()->json([
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting role: ' . $e->getMessage(),
            ], 500);
        }
    }
}
