<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "vm_image" (a virtual machine disk image, .img.xz) beside the existing
 * "disk_image" (a Raspberry Pi SD card image). Like disk_image it is never
 * offered over the heartbeat — it is only stored, tagged and downloaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE device_releases MODIFY COLUMN release_type ENUM('full', 'hotfix', 'disk_image', 'vm_image') NOT NULL DEFAULT 'full'");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM device_releases WHERE release_type = 'vm_image'");
        DB::statement("ALTER TABLE device_releases MODIFY COLUMN release_type ENUM('full', 'hotfix', 'disk_image') NOT NULL DEFAULT 'full'");
    }
};
