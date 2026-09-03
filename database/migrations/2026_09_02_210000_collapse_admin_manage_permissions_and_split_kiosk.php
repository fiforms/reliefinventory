<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu/permissions audit follow-up (PROJECT_ANALYSIS.md Part 12,
 * permissions-model-rework-2026-09-02 memory). Three changes, all decided
 * with Mark on 2026-09-02:
 *
 * 1. Collapse the admin- and manage- duplicate-tier permissions the audit
 *    found (a role holding only the "manage" half of a resource could open
 *    the resource's "Edit X" tile but silently 403 on Save/Delete, since
 *    write access lived under a separate, Administrator-only admin-*
 *    permission). manage-* now means full CRUD for: categories, locations,
 *    warehouses, uses, item types, package types, containers, streams,
 *    roles. The admin-* keys for these are deleted outright — cascadeOnDelete
 *    on permission_id takes role_permissions/person_permissions with them
 *    (see 2026_08_14_021144_create_permissions_tables.php). admin-people and
 *    admin-import/admin-system are NOT part of this: admin-people is a
 *    records-integrity concern (person deletion), not a CRUD tier — it's
 *    being replaced by deactivate/hide as part of the Partner/Donor rework,
 *    not widened; admin-import/admin-system gate genuinely different,
 *    higher-blast-radius actions.
 *
 * 2. Split the kiosk permission along "configure it" vs. "operate it"
 *    (permissions-tied-to-function-not-identity memory): manage-kiosk (new)
 *    gates the Kiosk Settings page, split off admin-system so it's
 *    individually delegable; operate-kiosk (renamed from
 *    operate-volunteer-kiosk) keeps its existing scope — enable/disable
 *    kiosk mode on a device, plus building-safety/closeout/roll-call.
 *
 * 3. Data-fix: the 2026_09_02_201632 migration that relabeled the
 *    "Volunteer Kiosk" tile to "Sign-in Kiosk" matched on the wrong
 *    link_url (`/volunteers/kiosk?enable=1`, which no menu_items row has —
 *    the real row is `/volunteers/kiosk`), so it silently updated zero rows.
 *    Fixed here alongside updating that same row's permission_key and the
 *    Kiosk Settings row's permission_key.
 */
return new class extends Migration
{
    private const COLLAPSED_ADMIN_KEYS = [
        'admin-categories',
        'admin-locations',
        'admin-warehouses',
        'admin-uses',
        'admin-itemtypes',
        'admin-packagetypes',
        'admin-containers',
        'admin-streams',
        'admin-roles',
    ];

    public function up(): void
    {
        DB::table('permissions')->whereIn('key', self::COLLAPSED_ADMIN_KEYS)->delete();

        foreach ([
            'manage-categories' => 'View, create, update, and delete item categories',
            'manage-locations' => 'View, create, update, and delete warehouse locations',
            'manage-warehouses' => 'View, create, update, and delete warehouses',
            'manage-uses' => 'View, create, update, and delete location uses',
            'manage-itemtypes' => 'View, create, update, and delete item types',
            'manage-packagetypes' => 'View, create, update, and delete package types',
            'manage-containers' => 'View, create, update, and delete generic containers and container types',
            'manage-streams' => 'View, create, update, and delete pickup streams',
            'manage-roles' => 'View, create, update, and delete roles',
        ] as $key => $description) {
            DB::table('permissions')->where('key', $key)->update(['description' => $description]);
        }

        DB::table('permissions')->where('key', 'operate-volunteer-kiosk')->update([
            'key' => 'operate-kiosk',
            'name' => 'operate-kiosk',
            'description' => 'Put a device into or out of Sign-in Kiosk mode, and confirm building-safety state (closeout, roll call)',
        ]);

        $manageKioskId = DB::table('permissions')->insertGetId([
            'key' => 'manage-kiosk',
            'name' => 'manage-kiosk',
            'description' => 'Configure Sign-in Kiosk settings: locations, guest types, and behavior',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $administratorId = DB::table('roles')->where('name', 'Administrator')->value('id');
        if ($administratorId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $administratorId,
                'permission_id' => $manageKioskId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('menu_items')
            ->where('link_url', '/volunteers/kiosk')
            ->where('link_text', 'Volunteer Kiosk')
            ->update(['link_text' => 'Sign-in Kiosk', 'permission_key' => 'operate-kiosk']);

        DB::table('menu_items')
            ->where('link_url', '/setup/kiosk-settings')
            ->update(['permission_key' => 'manage-kiosk']);
    }

    public function down(): void
    {
        DB::table('menu_items')
            ->where('link_url', '/setup/kiosk-settings')
            ->update(['permission_key' => 'admin-system']);

        DB::table('menu_items')
            ->where('link_url', '/volunteers/kiosk')
            ->where('link_text', 'Sign-in Kiosk')
            ->update(['link_text' => 'Volunteer Kiosk', 'permission_key' => 'operate-volunteer-kiosk']);

        DB::table('permissions')->where('key', 'manage-kiosk')->delete();

        DB::table('permissions')->where('key', 'operate-kiosk')->update([
            'key' => 'operate-volunteer-kiosk',
            'name' => 'operate-volunteer-kiosk',
            'description' => 'Operate the volunteer/visitor sign-in kiosk, and confirm building-safety state (closeout, roll call)',
        ]);

        // Collapsed admin-* permissions and their role/person grants are not
        // restored — re-run PermissionsSeeder's old definitions manually if
        // needed (not recommended; this migration reflects a deliberate
        // model change, not a mistake to roll back).
    }
};
