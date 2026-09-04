<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // Distinct from disabled_at (blocks *login*) and email_verified_at
            // (proves the address is real) — this is the actual "someone with
            // authority has vetted this account" gate. Null means "not yet
            // reviewed"; EnsureAccountApproved blocks a self-registered,
            // unapproved session from reaching anything but the registration
            // track/pending pages. Set explicitly by UserAdminController
            // (admin-created accounts, and the new approve() action), never
            // accepted from client-submitted validation rules — same pattern
            // as email_verified_at/disabled_at above.
            $table->timestamp('approved_at')->nullable()->after('requested_track');
        });

        // Every account that already exists predates this gate — treat all
        // of them as already-approved so nobody currently active gets
        // locked out. Only self-registrations going forward start pending.
        DB::table('people')->whereNotNull('email')->update(['approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
