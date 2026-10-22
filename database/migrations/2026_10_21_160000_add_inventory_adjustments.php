<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A manual inventory adjustment of a warehouse pallet's contents (shrinkage, damage, a recount...) is its own
 * orderdonations row of type 'adjustment' — the same "transaction" every ledger row belongs to — carrying the reason
 * in adjustment_reason and any explanation in comments. Its ledger rows name the pallet through stock_pallet_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orderdonations MODIFY type ENUM('order','donation','adjustment') NOT NULL");
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('adjustment_reason', 30)->nullable();
        });

        // Its own permission (mirrors PermissionsSeeder): Office and Administrator get it by default.
        $permissionId = DB::table('permissions')->where('key', 'adjust-inventory')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'adjust-inventory',
                'name' => 'adjust-inventory',
                'description' => 'Manually adjust the quantities recorded on a warehouse pallet, with a reason',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        foreach (['Office', 'Administrator'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'adjust-inventory')->delete();
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('adjustment_reason');
        });
        DB::statement("ALTER TABLE orderdonations MODIFY type ENUM('order','donation') NOT NULL");
    }
};
