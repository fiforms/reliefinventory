<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The Trust Console (/setup/trust) under its own permission, Administrator by default, next to R Station Devices. */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'manage-trusts')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'manage-trusts',
                'name' => 'manage-trusts',
                'description' => 'Manage the Trust Console: pair client apps and other instances, and set what each trust may do',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        $administratorId = DB::table('roles')->where('name', 'Administrator')->value('id');
        if ($administratorId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $administratorId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $devices = MenuItem::where('link_url', '/setup/devices')->first();
        if ($devices && ! MenuItem::where('link_url', '/setup/trust')->exists()) {
            MenuItem::create([
                'page_id' => $devices->page_id, 'link_text' => 'Trust Console', 'link_url' => '/setup/trust',
                'graphic_url' => $devices->graphic_url, 'order' => 68, 'group_label' => $devices->group_label,
                'permission_key' => 'manage-trusts',
            ]);
        }
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/trust')->delete();
        DB::table('permissions')->where('key', 'manage-trusts')->delete();
    }
};
