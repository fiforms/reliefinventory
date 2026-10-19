<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site print queue. A print server (an R Station Pi or a PC/Mac software
 * server) is a ManagedDevice; it reports the OS print queues it can see via
 * heartbeat, and each becomes a `printers` row that staff type (media) and
 * assign to a site (KioskLocation). `media` is a plain string key into
 * config/printing.php, not a DB enum. See rstation/PRINT_SERVER.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->string('platform', 20)->nullable(); // rstation|windows|macos|linux
            $table->json('reported_printers')->nullable(); // latest heartbeat snapshot: [{queue_name,state,state_message}]
            $table->timestamp('printers_reported_at')->nullable();
        });

        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('managed_device_id')->constrained('managed_devices')->cascadeOnDelete();
            $table->string('queue_name'); // OS/CUPS queue name as the print server reports it
            $table->foreignId('kiosk_location_id')->nullable()->constrained('kiosk_locations')->nullOnDelete();
            $table->string('media', 40)->nullable(); // null until staff type it; key into config('printing.media')
            $table->boolean('active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['managed_device_id', 'queue_name']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->nullable()->constrained('printers')->nullOnDelete();
            $table->foreignId('kiosk_location_id')->nullable()->constrained('kiosk_locations')->nullOnDelete();
            $table->string('media', 40);
            $table->string('title');
            $table->string('document', 60)->nullable(); // DocumentRegistry key, for support/diagnostics
            $table->string('pdf_path')->nullable(); // under config('printing.disk'); nulled once purged
            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('status', 20)->default('queued')->index(); // queued|claimed|printed|failed|cancelled
            $table->foreignId('claimed_by_device_id')->nullable()->constrained('managed_devices')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['printer_id', 'status']);
        });

        Schema::create('print_job_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('managed_device_id')->nullable()->constrained('managed_devices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $permissions = [
            'print-documents' => 'Send documents to a site printer and see your own print jobs',
            'manage-print-queue' => 'See, cancel, retry, and divert any print job in the print queue',
        ];
        // role => permission keys it gets by default (mirrors PermissionsSeeder)
        $grants = [
            'print-documents' => ['Volunteer', 'Team Leader', 'Office', 'Sorting and Inventory', 'Administrator'],
            'manage-print-queue' => ['Office', 'Administrator'],
        ];
        foreach ($permissions as $key => $description) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => $key,
                'name' => $key,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($grants[$key] as $roleName) {
                $roleId = DB::table('roles')->where('name', $roleName)->value('id');
                if ($roleId) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', ['print-documents', 'manage-print-queue'])->delete();
        Schema::dropIfExists('print_job_status_log');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->dropColumn(['platform', 'reported_printers', 'printers_reported_at']);
        });
    }
};
