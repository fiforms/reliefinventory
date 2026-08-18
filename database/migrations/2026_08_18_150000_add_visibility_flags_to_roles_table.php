<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two independent flags, not one, because Customer needs to appear in
 * both pickers for different reasons (party tracking on the People form
 * vs. a login-capable ordering role on the new User Administration page),
 * while Team Leader appears in neither going forward — see TODO.md item 1
 * and the "User Administration" plan for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('visible_in_people_form')->default(false);
            $table->boolean('visible_in_user_admin')->default(false);
        });

        DB::table('roles')->whereIn('name', ['Customer', 'Donor', 'Volunteer'])
            ->update(['visible_in_people_form' => true]);

        DB::table('roles')->whereIn('name', ['Administrator', 'Customer'])
            ->update(['visible_in_user_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['visible_in_people_form', 'visible_in_user_admin']);
        });
    }
};
