<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login gate for admin-initiated deactivation (the User Administration build) — nullable
 * so reactivation is just clearing the timestamp, no data loss. Matches
 * the existing *_at idiom (email_verified_at, badge_verified_at) rather
 * than a boolean flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
