<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A print server's private "wake-up" channel name (see
 * ManagedDevice::wakeChannel()). Generated lazily, rotated on re-pair,
 * cleared on revoke.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->string('wake_channel', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->dropUnique(['wake_channel']);
            $table->dropColumn('wake_channel');
        });
    }
};
