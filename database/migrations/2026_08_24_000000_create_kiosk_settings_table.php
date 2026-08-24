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
     * Singleton settings row (always id=1) for the volunteer kiosk's
     * front-screen banner — an admin-settable location/facility name (e.g.
     * "Welcome to the Statesville Warehouse") shown above the "Facility
     * Sign-In/Sign-Out" tagline. Same singleton-row shape as
     * pin_login_settings: one well-known field is simpler than a generic
     * key-value store for exactly one feature's config. Null
     * welcome_message means the kiosk falls back to a generic greeting.
     */
    public function up(): void
    {
        Schema::create('kiosk_settings', function (Blueprint $table) {
            $table->id();
            $table->string('welcome_message')->nullable();
            $table->timestamps();
        });

        DB::table('kiosk_settings')->insert([
            'id' => 1,
            'welcome_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_settings');
    }
};
