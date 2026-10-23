<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when an administrator creates an account with a password they chose
 * (User Administration) and asks the person to pick their own on first
 * login. EnsurePasswordChanged holds the session on the change-password
 * screen until it's cleared; any successful password change clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('password_change_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('password_change_required');
        });
    }
};
