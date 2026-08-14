<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One permission per resource (manage-* for the volunteer-tier CRUD that
 * used to be role:4, admin-* for the destructive/structural ops that used
 * to be role:32768), plus general-access for the handful of placeholder/
 * non-resource pages that don't touch any specific resource yet.
 *
 * Default role grants preserve today's effective access exactly: Volunteer
 * and Team Leader get every manage-* key (matching role:4 today — they're
 * identical for now since nothing currently distinguishes them beyond
 * role_bitpack magnitude; a real difference can be layered in later via
 * person_permissions or additional role grants once there's a concrete
 * need). Administrator gets everything (matching role:32768 + role:4
 * today, since an admin always passed both). Customer/Donor get nothing
 * (they never corresponded to a route gate).
 */
class PermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'general-access' => 'Access placeholder/non-resource pages not yet tied to a specific permission',
        'manage-people' => 'View, create, and update people (customers/donors)',
        'admin-people' => 'Delete people',
        'manage-orders' => 'View, update, and delete orders/donations',
        'manage-items' => 'Manage the item catalog',
        'manage-units' => 'Manage units of measure',
        'manage-categories' => 'View and create item categories',
        'admin-categories' => 'Update and delete item categories',
        'manage-locations' => 'View warehouse locations',
        'admin-locations' => 'Create, update, and delete warehouse locations',
        'manage-warehouses' => 'View warehouses',
        'admin-warehouses' => 'Create, update, and delete warehouses',
        'manage-uses' => 'View location uses',
        'admin-uses' => 'Create, update, and delete location uses',
        'manage-itemtypes' => 'View and create item types',
        'admin-itemtypes' => 'Update and delete item types',
        'manage-packagetypes' => 'View package types',
        'admin-packagetypes' => 'Create, update, and delete package types',
        'manage-sorting' => 'Run donation sorting sessions',
        'manage-receiving' => 'Record dock-side intake and manage donation close-out',
        'manage-pallets' => 'Manage pallets and pallet status history',
        'manage-trucks' => 'Manage trucks',
        'manage-containers' => 'View and manage generic containers',
        'admin-containers' => 'Create, update, and delete container types',
        'manage-streams' => 'View pickup streams',
        'admin-streams' => 'Create, update, and delete pickup streams',
        'manage-roles' => 'View roles',
        'admin-roles' => 'Create, update, and delete roles',
        'manage-counties' => 'Create, update, and delete counties',
    ];

    private const VOLUNTEER_TIER_KEYS = [
        'general-access', 'manage-people', 'manage-orders', 'manage-items', 'manage-units',
        'manage-categories', 'manage-locations', 'manage-warehouses', 'manage-uses',
        'manage-itemtypes', 'manage-packagetypes', 'manage-sorting', 'manage-receiving',
        'manage-pallets', 'manage-trucks', 'manage-containers', 'manage-streams',
        'manage-roles', 'manage-counties',
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(
            fn ($description, $key) => Permission::firstOrCreate(['key' => $key], ['name' => $key, 'description' => $description])
        );

        $volunteer = Role::where('name', 'Volunteer')->first();
        $teamLeader = Role::where('name', 'Team Leader')->first();
        $administrator = Role::where('name', 'Administrator')->first();

        $volunteerTier = $permissions->only(self::VOLUNTEER_TIER_KEYS)->pluck('id');

        foreach ([$volunteer, $teamLeader] as $role) {
            if ($role) {
                $role->permissions()->syncWithoutDetaching($volunteerTier);
            }
        }

        if ($administrator) {
            $administrator->permissions()->syncWithoutDetaching($permissions->pluck('id'));
        }
    }
}
