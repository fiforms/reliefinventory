<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds two new named staff roles for the User Administration page
 * (TODO.md item 1). Deliberately additive — inserts new rows rather than
 * repeating the delete-and-reseed pattern the original roles migration
 * used, since this table now carries live production role assignments
 * (see the "User Administration" plan's context section for why Team
 * Leader/Volunteer are left untouched rather than migrated).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insertOrIgnore([
            [
                'id' => 20,
                'name' => 'Sorting and Inventory Staff',
                'description' => 'Warehouse-side staff: receiving, sorting, pallets, and the item/location catalog. No order or people-management access beyond donor lookup.',
                'visible_in_people_form' => false,
                'visible_in_user_admin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 21,
                'name' => 'Office Staff',
                'description' => 'Full operational access (orders, inventory, reporting) but no admin/setup permissions.',
                'visible_in_people_form' => false,
                'visible_in_user_admin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('id', [20, 21])->delete();
    }
};
