<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real multi-location support for the volunteer kiosk (2026-08-26 design
 * pass) — until now the kiosk had exactly one global welcome_message
 * (kiosk_settings) and no concept of "which site is this device at all".
 * `name` is the required header shown on the kiosk; `welcome_message` is a
 * separate, optional line shown only when non-blank (no generic "Welcome!"
 * filler — the location name alone is already a real header). Which
 * location a given kiosk device belongs to lives on trusted_devices, not
 * here — see the next migration.
 *
 * Seeds one row so existing single-site installs need no setup step, with
 * welcome_message backfilled from the (about to be retired) global
 * kiosk_settings.welcome_message so nobody's current banner text is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('welcome_message')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $existingWelcomeMessage = Schema::hasTable('kiosk_settings')
            ? DB::table('kiosk_settings')->where('id', 1)->value('welcome_message')
            : null;

        DB::table('kiosk_locations')->insert([
            'id' => 1,
            'name' => 'Main Location',
            'welcome_message' => $existingWelcomeMessage,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_locations');
    }
};
