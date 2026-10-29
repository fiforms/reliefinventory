<?php

use App\Services\TrainingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sorting's item/type/unit/category lookups 403'd for trainees; grant the Trainee role the catalog keys. */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', TrainingMode::ROLE)->value('id');
        if (! $roleId) {
            return;
        }
        $keys = ['manage-items', 'manage-itemtypes', 'manage-units', 'manage-categories'];
        foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $id) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
