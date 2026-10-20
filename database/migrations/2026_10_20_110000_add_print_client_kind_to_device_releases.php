<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds kind "print_client": an installer of the RI Print Client for one platform
 * (stored in `architecture`, e.g. "windows" or "macos-arm64"). Like the disk/VM
 * images it is download-only — never offered to devices, and served only to
 * signed-in people who can pair devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE device_releases MODIFY COLUMN kind ENUM('app', 'os', 'print_client') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM device_releases WHERE kind = 'print_client'");
        DB::statement("ALTER TABLE device_releases MODIFY COLUMN kind ENUM('app', 'os') NOT NULL");
    }
};
