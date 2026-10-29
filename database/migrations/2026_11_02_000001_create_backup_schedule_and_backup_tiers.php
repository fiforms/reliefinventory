<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rollback snapshots for a warehouse (scripts/BACKUPS.md): `backups.tiers` says which retention tiers a scheduled zip belongs to
 * (hourly, daily, weekly, monthly; null = made by hand or an older install, which keeps the flat expires_at rule), and the single-row
 * `backup_schedule` is the warehouse admin's choice of what is made when and how many of each are kept. Until now the Docker
 * schedule was the scheduler container's environment only (RI_BACKUP_HOUR, RI_BACKUP_KEEP_DAYS, RI_BACKUP_SCOPE); those
 * variables now only seed the row, so an existing install keeps behaving the way it did (one daily backup, kept RI_BACKUP_KEEP_DAYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('tiers', 60)->nullable()->after('scope');   // comma list of hourly,daily,weekly,monthly
        });

        Schema::create('backup_schedule', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();   // always 1
            $table->boolean('hourly_enabled')->default(false);
            $table->unsignedSmallInteger('hourly_keep')->default(24);
            $table->boolean('daily_enabled')->default(true);
            $table->unsignedTinyInteger('daily_hour')->default(2);
            $table->unsignedSmallInteger('daily_keep')->default(14);
            $table->boolean('daily_with_files')->default(false);
            $table->boolean('weekly_enabled')->default(false);
            $table->unsignedTinyInteger('weekly_dow')->default(0);   // 0 = Sunday
            $table->unsignedSmallInteger('weekly_keep')->default(8);
            $table->boolean('weekly_with_files')->default(false);
            $table->boolean('monthly_enabled')->default(false);
            $table->unsignedSmallInteger('monthly_keep')->default(12);
            $table->boolean('monthly_with_files')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();   // complete | failed
            $table->timestamps();
        });

        $withFiles = config('backup.container.scope') === 'full';
        DB::table('backup_schedule')->insert([
            'id' => 1,
            'daily_enabled' => (bool) config('backup.container.enabled'),
            'daily_hour' => max(0, min(23, (int) config('backup.container.hour'))),
            'daily_keep' => max(1, (int) config('backup.keep_days')),
            'daily_with_files' => $withFiles,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedule');
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('tiers');
        });
    }
};
