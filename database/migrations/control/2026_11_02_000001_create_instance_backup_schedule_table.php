<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When the whole-instance backup (control + accounts + every warehouse) is made automatically and how many are kept: one row, written by an
// instance administrator on Instance Admin and read by the provisioner, the only process that can make the backup. Off until an instance
// administrator turns it on. See App\Models\Control\InstanceBackupSchedule.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('instance_backup_schedule', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();   // always 1
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('daily_hour')->default(3);
            $table->unsignedSmallInteger('daily_keep')->default(7);
            $table->boolean('daily_with_files')->default(false);
            $table->boolean('weekly_enabled')->default(false);
            $table->unsignedTinyInteger('weekly_dow')->default(0);   // 0 = Sunday
            $table->unsignedSmallInteger('weekly_keep')->default(4);
            $table->boolean('weekly_with_files')->default(false);
            // Written by the provisioner after each scheduled run. The last SUCCESS decides what is due (a failure leaves it due, so the
            // next hour tries again), and is kept here rather than found by looking at the zips so every container can ask.
            $table->timestamp('last_daily_at')->nullable();
            $table->timestamp('last_weekly_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();   // complete | failed
            $table->string('last_message', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('instance_backup_schedule');
    }
};
