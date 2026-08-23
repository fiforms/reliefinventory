<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up from the building-safety design pass: kiosk operation
 * (including the new PIN-gated closeout/roll-call actions) is narrower
 * than originally scoped — Office + Administrator by default, not the
 * whole front-line Volunteer/Team-Leader/Sorting-and-Inventory tier, since
 * it now covers declaring official building-safety state, not just
 * signing volunteers in and out. A specific person (e.g. a night security
 * officer with no other role) can still be granted it individually via the
 * existing person_permissions override — no new mechanism needed.
 *
 * Renamed key to describe what it actually gates now: operating the kiosk
 * (setup, sign-in/out) and the closeout/roll-call actions, not just
 * "managing hours" (which was never really accurate — see
 * certify-volunteer-hours for the actual hours-compliance permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where('key', 'manage-volunteer-hours')->update([
            'key' => 'operate-volunteer-kiosk',
            'name' => 'operate-volunteer-kiosk',
            'description' => 'Operate the volunteer/visitor sign-in kiosk, and confirm building-safety state (closeout, roll call)',
        ]);

        $permissionId = DB::table('permissions')->where('key', 'operate-volunteer-kiosk')->value('id');
        if ($permissionId) {
            $roleIds = DB::table('roles')->whereIn('name', ['Volunteer', 'Team Leader', 'Sorting and Inventory'])->pluck('id');
            DB::table('role_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'operate-volunteer-kiosk')->update([
            'key' => 'manage-volunteer-hours',
            'name' => 'manage-volunteer-hours',
            'description' => 'Operate the volunteer/visitor sign-in kiosk',
        ]);
        // Broad-tier grants are not restored — re-run PermissionsSeeder if needed.
    }
};
