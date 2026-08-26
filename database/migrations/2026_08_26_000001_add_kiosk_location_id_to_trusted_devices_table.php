<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which KioskLocation a given device is running as — assigned when kiosk
 * mode is enabled (auto-picked if only one active location exists, chosen
 * explicitly otherwise), not a single global setting, so multiple physical
 * kiosks can each show their own location's header. Backfills any device
 * already in kiosk mode onto the seeded default location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->foreignId('kiosk_location_id')->nullable()->after('kiosk_mode_enabled_by_person_id')
                ->constrained('kiosk_locations')->nullOnDelete();
        });

        DB::table('trusted_devices')->where('kiosk_mode', true)->update(['kiosk_location_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_location_id');
        });
    }
};
