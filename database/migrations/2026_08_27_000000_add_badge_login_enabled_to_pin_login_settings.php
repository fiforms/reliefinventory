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
     * Separates "offer badge scanning at all" from require_badge_and_pin
     * (which only controls whether it's mandatory). No badges are issued
     * yet, so the optional "Or scan your badge" step on the unlock screen
     * was pure friction with nothing behind it — this turns it off without
     * removing the feature, so it can be flipped back on once badges exist.
     */
    public function up(): void
    {
        Schema::table('pin_login_settings', function (Blueprint $table) {
            $table->boolean('badge_login_enabled')->default(false)->after('require_badge_and_pin');
        });

        DB::table('pin_login_settings')->where('id', 1)->update(['badge_login_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('pin_login_settings', function (Blueprint $table) {
            $table->dropColumn('badge_login_enabled');
        });
    }
};
