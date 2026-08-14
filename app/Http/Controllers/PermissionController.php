<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Permission;

/**
 * Read-only listing of permissions — used by the People edit form to offer
 * per-person overrides. Permissions themselves aren't created/edited
 * through the UI; they're defined in PermissionsSeeder.
 */
class PermissionController extends Controller
{
    public function index()
    {
        return response()->json([
            'records' => Permission::orderBy('key')->get(),
        ]);
    }
}
