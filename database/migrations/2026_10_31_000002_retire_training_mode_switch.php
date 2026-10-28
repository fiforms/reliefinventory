<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Services\TrainingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Training lives in a warehouse of its own (MULTI_WAREHOUSE.md §12), so the switch that made a live warehouse behave like a classroom
// goes: the on/off flag, who turned it on, and the reset point (a database backup taken when it was turned on). Whether a training
// warehouse is on is now its status. In a warehouse that isn't a training one the Trainee role is hidden from the user lists
// (anyone already holding it keeps it), no role carries `manage-training`; the class tools are inert there anyway (HasPermissions drops `manage-training`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'enabled_at', 'enabled_by', 'reset_point_file', 'reset_point_at']);
        });

        DB::table('menu_items')->where('link_url', '/setup/training')->update(['link_text' => 'Training Warehouse']);

        if (! TrainingMode::active()) {
            DB::table('roles')->where('name', TrainingMode::ROLE)->update(['visible_in_user_admin' => false]);
            // No role of a real warehouse carries the class tools' key (the seeder no longer gives it either).
            DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->where('key', 'manage-training')->select('id'))->delete();
        }
    }

    public function down(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->unsignedBigInteger('enabled_by')->nullable();
            $table->string('reset_point_file')->nullable();
            $table->timestamp('reset_point_at')->nullable();
        });
    }
};
