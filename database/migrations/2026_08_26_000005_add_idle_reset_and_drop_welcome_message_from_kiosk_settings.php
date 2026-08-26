<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * welcome_message moved to kiosk_locations (per-location banner, since
 * multi-location is the whole point of this pass — see
 * 2026_08_26_000000_create_kiosk_locations_table.php, which already
 * backfilled it onto the seeded default location before this drops the
 * column here). idle_reset_minutes is new: how long the kiosk screen sits
 * idle before resetting to the welcome/grid view; null means never.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_settings', function (Blueprint $table) {
            $table->unsignedInteger('idle_reset_minutes')->nullable()->after('id');
            $table->dropColumn('welcome_message');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_settings', function (Blueprint $table) {
            $table->string('welcome_message')->nullable();
            $table->dropColumn('idle_reset_minutes');
        });
    }
};
