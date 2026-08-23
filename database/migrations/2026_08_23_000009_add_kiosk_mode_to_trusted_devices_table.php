<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk lock mode (2026-08-23 design pass): a device already approved for
 * PIN unlock can additionally be put into kiosk_mode by someone with
 * operate-volunteer-kiosk — this logs that person out and, from then on,
 * lets THIS device reach the volunteer kiosk page/endpoints without any
 * login at all (see KioskModeController and the new guest-access
 * middleware). kiosk_mode auto-clears the moment anyone successfully logs
 * in (password or PIN) on this device — see AuthenticatedSessionController
 * and UnlockController — so "getting back to the real app" is just
 * logging in, nothing separate to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->boolean('kiosk_mode')->default(false)->after('status');
            $table->timestamp('kiosk_mode_enabled_at')->nullable()->after('kiosk_mode');
            $table->foreignId('kiosk_mode_enabled_by_person_id')->nullable()
                ->after('kiosk_mode_enabled_at')->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropColumn(['kiosk_mode', 'kiosk_mode_enabled_at', 'kiosk_mode_enabled_by_person_id']);
        });
    }
};
