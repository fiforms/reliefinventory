<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    private const validation = [
        'name' => 'required|string|max:255|unique:roles,name',
        'description' => 'nullable|string',
    ];

    /**
     * Retrieve roles. ?context=people restricts to the party-tracking
     * roles shown on the main People form (Partner/Donor/Volunteer);
     * ?context=users restricts to the login-capable roles offered on the
     * User Administration page (Administrator, Partner, and the other
     * staff roles) — see the visible_in_people_form/visible_in_user_admin
     * columns. No context param returns every role, unchanged.
     *
     * Always eager-loads `permissions` (id, key only) — a role is just a
     * named preset of permissions (TODO.md item 1 follow-up), so the User
     * Administration page needs to know what each role actually grants in
     * order to show/apply it as a one-tap preset over the flat permission
     * checklist, rather than treating role membership as its own opaque
     * grant.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $roles = match ($request->query('context')) {
            'people' => Role::where('visible_in_people_form', true),
            'users' => Role::where('visible_in_user_admin', true),
            default => Role::query(),
        };
        $roles = $roles->with('permissions:id,key')->get();
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
     * @return JsonResponse
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
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,'.$id,
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
     * @param  int  $id
     * @return JsonResponse
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
                'message' => 'Error deleting role: '.$e->getMessage(),
            ], 500);
        }
    }
}
