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
     * Singleton settings row (always id=1) for the shared-terminal PIN
     * unlock feature — a lock-screen-style quick re-auth for people who've
     * already done a real email+password login on an admin-approved
     * device. Off by default; an administrator must explicitly turn it on.
     */
    public function up(): void
    {
        Schema::create('pin_login_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // 'time_of_day': every grant expires at the next occurrence of
            // trust_time_of_day, regardless of when it was granted (a daily
            // reset). 'session_duration': granted_at + trust_session_minutes,
            // per grant. 'indefinite': never expires until explicit logout.
            $table->enum('trust_mode', ['time_of_day', 'session_duration', 'indefinite'])
                ->default('session_duration');
            $table->time('trust_time_of_day')->nullable();
            $table->unsignedInteger('trust_session_minutes')->nullable()->default(480);
            // Requires an actual badge scan (not just tapping a name tile)
            // plus the PIN — two-factor (something-you-have + something-
            // you-know), not just a UI shortcut for picking who you are.
            $table->boolean('require_badge_and_pin')->default(false);
            $table->timestamps();
        });

        DB::table('pin_login_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'trust_mode' => 'session_duration',
            'trust_session_minutes' => 480,
            'require_badge_and_pin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_login_settings');
    }
};
