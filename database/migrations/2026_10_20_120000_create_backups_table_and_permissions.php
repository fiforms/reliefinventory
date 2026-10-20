<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On-demand backup zips (app/Services/BackupService). `manage-backups` runs/downloads/deletes them;
 * `view-app-key` is a separate, narrower key for the one panel that shows the application key —
 * Administrator-only by default, like manage-firmware, and deliberately not implied by the other.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'manage-backups' => 'Create, download, and delete full backup files (database and uploads)',
        'view-app-key' => 'Reveal the application encryption key (APP_KEY) so it can be written down',
    ];

    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('queued'); // queued | running | complete | failed
            $table->boolean('include_uploads')->default(false);
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->string('progress')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $administratorId = DB::table('roles')->where('name', 'Administrator')->value('id');
        foreach (self::PERMISSIONS as $key => $description) {
            $id = DB::table('permissions')->where('key', $key)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'key' => $key, 'name' => $key, 'description' => $description,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            if ($administratorId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $administratorId, 'permission_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $system = MenuItem::where('link_url', '/setup/system')->first();
        if ($system) {
            MenuItem::create([
                'page_id' => $system->page_id, 'link_text' => 'Backup Files', 'link_url' => '/setup/backups',
                'graphic_url' => $system->graphic_url, 'order' => 81, 'group_label' => $system->group_label,
                'permission_key' => 'manage-backups',
            ]);
        }
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/backups')->delete();
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
        Schema::dropIfExists('backups');
    }
};
