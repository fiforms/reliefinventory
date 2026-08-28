<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // What a self-registered user said they're here for, captured
            // right after email verification — volunteer / donor / partner.
            // Purely informational until the real approval workflow exists;
            // it doesn't grant anything on its own (see people_roles /
            // HasPermissions for what actually governs access).
            $table->enum('requested_track', ['volunteer', 'donor', 'partner'])
                ->nullable()
                ->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('requested_track');
        });
    }
};
