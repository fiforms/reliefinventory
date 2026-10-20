<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton (always id=1), same shape as pin_login_settings. `source_url` is another Relief
 * Inventory server whose release catalogue this instance's R Stations should use instead of
 * this one's own: the heartbeat still goes to this (paired) server, which answers with a
 * pointer, and the device asks the source for releases (see DeviceHeartbeatController).
 * Null = this instance serves its own releases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmware_settings', function (Blueprint $table) {
            $table->id();
            $table->string('source_url', 2048)->nullable();
            $table->timestamps();
        });
        DB::table('firmware_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('firmware_settings');
    }
};
