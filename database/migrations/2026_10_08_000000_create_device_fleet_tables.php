<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server side of the reliefkiosk device management contract
 * (kiosk/reliefkiosk/docs/DEVICE_CONTRACT.md): paired Raspberry Pi kiosks,
 * their single-use pairing codes, and the uploaded app/OS releases offered
 * to them. Named managed_devices, not "devices"/"kiosk_*", to stay clear of
 * trusted_devices (browser PIN-login trust) and kiosk_locations (the
 * sign-in kiosk's sites) — different concepts. The contract's "entity" (the
 * owner a device joins) is a KioskLocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_uuid')->unique();
            $table->string('name');
            $table->string('mac_address')->nullable();
            $table->foreignId('kiosk_location_id')->nullable()->constrained('kiosk_locations')->nullOnDelete();
            $table->string('token_hash', 64)->nullable()->unique(); // sha256 of the bearer token; never the token itself
            $table->timestamp('revoked_at')->nullable();
            $table->string('update_channel', 20)->default('stable');
            $table->boolean('os_auto_update_enabled')->default(true);
            // What this kiosk does (see config/devices.php 'functions'); null = not yet configured.
            $table->string('kiosk_function', 40)->nullable();
            $table->string('language', 10)->nullable();
            $table->unsignedInteger('language_revision')->default(0);
            // Snapshot from the last heartbeat.
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();
            $table->string('architecture')->nullable();
            $table->string('hostname')->nullable();
            $table->decimal('cpu_temp_c', 5, 1)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->foreignId('kiosk_location_id')->constrained('kiosk_locations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('managed_device_id')->nullable()->constrained('managed_devices')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('device_releases', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['app', 'os']);
            $table->string('version');
            $table->string('architecture');
            $table->enum('release_type', ['full', 'hotfix', 'disk_image'])->default('full');
            $table->string('required_base_version')->nullable(); // hotfix only
            $table->string('sha256', 64);
            $table->string('storage_path'); // on the local disk; random directory so the download URL isn't guessable
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('download_token', 64)->unique();
            $table->json('channels')->nullable(); // channels this release is currently tagged on; empty = archived
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });

        $permissionId = DB::table('permissions')->insertGetId([
            'key' => 'manage-devices',
            'name' => 'manage-devices',
            'description' => 'Pair, configure, and revoke kiosk devices, and upload and tag their software releases',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $administratorId = DB::table('roles')->where('name', 'Administrator')->value('id');
        if ($administratorId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $administratorId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $kioskSettings = MenuItem::where('link_url', '/setup/kiosk-settings')->first();
        if ($kioskSettings) {
            MenuItem::create([
                'page_id' => $kioskSettings->page_id,
                'link_text' => 'Kiosk Devices',
                'link_url' => '/setup/devices',
                'graphic_url' => '/img/edit-users-icon.webp',
                'order' => 65,
                'group_label' => $kioskSettings->group_label,
                'permission_key' => 'manage-devices',
            ]);
        }
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/devices')->delete();
        DB::table('permissions')->where('key', 'manage-devices')->delete();
        Schema::dropIfExists('device_releases');
        Schema::dropIfExists('device_pairing_codes');
        Schema::dropIfExists('managed_devices');
    }
};
