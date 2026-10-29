<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Docker self-update flag: one row, written by the app ("check" / "update" requested) and by the host-side updater
// (docker/updater/ri-updater.py) as it works. See App\Models\Control\InstanceUpdate.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('instance_update', function (Blueprint $table) {
            $table->id();
            $table->string('action', 10)->nullable();            // check | update — what is waiting for the updater; null otherwise
            $table->string('state', 20)->default('idle');        // idle, requested, checking, pulling, restarting, done, failed
            $table->string('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('message', 1000)->nullable();
            $table->string('current_version', 60)->nullable();
            $table->string('available_version', 60)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('state_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('instance_update');
    }
};
