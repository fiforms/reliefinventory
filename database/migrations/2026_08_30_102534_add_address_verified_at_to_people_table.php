<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks whether the CURRENT address/city/state/zip on this record has
     * been checked against geocod.io — never set directly from client
     * input (see PeopleController::update), so it's trustworthy: stamped
     * `now()` only when a geocode lookup actually ran and was accepted
     * (silently for an exact match, or via AddressCorrectionCheck), and
     * cleared back to null the moment any of those four fields changes,
     * so it never lies about stale data. This is what lets an address
     * typed while OfflineModeSetting was on get a one-click "Verify
     * Address" opportunity next time someone opens that record, instead
     * of either a mass batch job (burns geocod.io's free-tier daily quota
     * for records nobody's actively using) or nothing at all.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('address_verified_at')->nullable()->after('zip');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('address_verified_at');
        });
    }
};
