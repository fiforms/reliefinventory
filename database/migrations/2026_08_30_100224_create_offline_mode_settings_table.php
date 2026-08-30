<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton settings row (always id=1) — a single instance-wide switch
     * for a warehouse with no reliable internet, rather than a checklist of
     * individual internet-dependent features to remember to turn off. When
     * on, this disables Cloudflare Turnstile and geocod.io address lookups
     * (see OfflineModeSetting::isOffline(), checked at both call sites) —
     * neither is required for normal operation, both just fail/timeout
     * ungracefully-ish without a real network, so this makes that explicit
     * and instant instead of every page eating a timeout. Off by default.
     */
    public function up(): void
    {
        Schema::create('offline_mode_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        DB::table('offline_mode_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_mode_settings');
    }
};
