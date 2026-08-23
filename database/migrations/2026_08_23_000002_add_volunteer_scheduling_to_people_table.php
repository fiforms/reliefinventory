<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volunteer hours kiosk (PROJECT_ANALYSIS.md Part 5): the kiosk's default
 * tile grid needs a single flag to gate membership on, separate from
 * is_volunteer (a permanent fact about the person) and from disabled_at
 * (login access, unrelated). volunteer_active is that flag — admins can
 * toggle it directly at any time.
 *
 * volunteer_window_start/end are optional inputs that drive
 * volunteer_active automatically for a volunteer on a known-duration
 * commitment (see volunteers:sync-active-windows, added alongside a new
 * hourly systemd timer mirroring reliefinventory-backup.timer): flips
 * volunteer_active true on start date, false the day after end date, so
 * nobody has to come back and adjust it by hand. Manual toggles in between
 * are left alone — the sync only touches the flag on those two transition
 * days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('volunteer_active')->default(true)->after('is_volunteer');
            $table->date('volunteer_window_start')->nullable()->after('volunteer_active');
            $table->date('volunteer_window_end')->nullable()->after('volunteer_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['volunteer_active', 'volunteer_window_start', 'volunteer_window_end']);
        });
    }
};
