<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to the User Administration build (TODO.md item 1):
 *
 * - "Staff" implies paid employment, which is out of scope for this app —
 *   drop it from the two new role names ("Sorting and Inventory Staff" ->
 *   "Sorting and Inventory", "Office Staff" -> "Office").
 * - Whether someone is a volunteer is a fact about the *person*, not a
 *   permission role — a volunteer can just as easily be the office
 *   manager or an administrator. Add people.is_volunteer (feeds future
 *   volunteer-hours/FEMA-reporting tracking, see PROJECT_ANALYSIS.md
 *   Part 5) and retire the "Volunteer" role from both pickers, backfilling
 *   the flag for anyone currently holding it so no information is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('id', 20)->update([
            'name' => 'Sorting and Inventory',
            'description' => 'Warehouse-side work: receiving, sorting, pallets, and the item/location catalog. No order or people-management access beyond donor lookup.',
        ]);
        DB::table('roles')->where('id', 21)->update([
            'name' => 'Office',
            'description' => 'Full operational access (orders, inventory, reporting) but no admin/setup permissions.',
        ]);

        Schema::table('people', function (Blueprint $table) {
            $table->boolean('is_volunteer')->default(false);
        });

        $volunteerRole = DB::table('roles')->where('name', 'Volunteer')->first();
        if ($volunteerRole) {
            DB::table('people')->whereIn('id', function ($query) use ($volunteerRole) {
                $query->select('person_id')->from('people_roles')->where('role_id', $volunteerRole->id);
            })->update(['is_volunteer' => true]);

            DB::table('roles')->where('id', $volunteerRole->id)->update([
                'visible_in_people_form' => false,
                'visible_in_user_admin' => false,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('id', 20)->update([
            'name' => 'Sorting and Inventory Staff',
            'description' => 'Warehouse-side staff: receiving, sorting, pallets, and the item/location catalog. No order or people-management access beyond donor lookup.',
        ]);
        DB::table('roles')->where('id', 21)->update([
            'name' => 'Office Staff',
            'description' => 'Full operational access (orders, inventory, reporting) but no admin/setup permissions.',
        ]);

        DB::table('roles')->where('name', 'Volunteer')->update(['visible_in_people_form' => true]);

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('is_volunteer');
        });
    }
};
