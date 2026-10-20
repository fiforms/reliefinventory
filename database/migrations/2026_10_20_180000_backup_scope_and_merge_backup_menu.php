<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backups get a `scope` (database | full | storage) in place of the old include_uploads flag, and the
 * separate "Backup Files" page is merged into Updates & Backups (/setup/system), so its menu entry goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('scope', 20)->default('database')->after('status'); // database | full | storage
        });
        DB::table('backups')->where('include_uploads', true)->update(['scope' => 'full']);
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('include_uploads');
        });

        MenuItem::where('link_url', '/setup/backups')->delete();
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('include_uploads')->default(false)->after('status');
        });
        DB::table('backups')->whereIn('scope', ['full', 'storage'])->update(['include_uploads' => true]);
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('scope');
        });

        $system = MenuItem::where('link_url', '/setup/system')->first();
        if ($system && ! MenuItem::where('link_url', '/setup/backups')->exists()) {
            MenuItem::create([
                'page_id' => $system->page_id, 'link_text' => 'Backup Files', 'link_url' => '/setup/backups',
                'graphic_url' => $system->graphic_url, 'order' => 81, 'group_label' => $system->group_label,
                'permission_key' => 'manage-backups',
            ]);
        }
    }
};
