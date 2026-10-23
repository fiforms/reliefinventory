<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Services\TrainingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Training Mode (see App\Services\TrainingMode): a switch that lets approved devices log trainees in by tapping
 * their name, with a capped, non-admin permission set, plus an append-only activity log every station action
 * writes (who did what, always on) that the training report card reads.
 *
 * - training_settings: singleton (id = 1), same shape as pin_login_settings.
 * - people.is_trainee / training_team: who gets a name tile, and which of the class's teams (1–5) they're on.
 * - drivers.training_team: a "Team N Driver" made by Prepare Training, so a Driver Portal delivery counts for that team.
 * - activity_log: one row per station action (App\Services\ActivityRecorder), never updated.
 * - manage-training permission (Office + Administrator) and the Trainee role (TrainingMode::TRAINING_KEYS).
 * - Menu: Setup > Training, Reports > Training Report Cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->unsignedBigInteger('enabled_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->json('prepared_summary')->nullable(); // what the last Prepare Training loaded (the setup packet)
            $table->timestamps();
        });
        DB::table('training_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('people', function (Blueprint $table) {
            $table->boolean('is_trainee')->default(false);
            $table->unsignedTinyInteger('training_team')->nullable();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedTinyInteger('training_team')->nullable();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('person_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedTinyInteger('training_team')->nullable();
            $table->unsignedTinyInteger('station')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['person_id', 'created_at']);
            $table->index(['training_team', 'created_at']);
            $table->index('created_at');
        });

        $permissionId = DB::table('permissions')->where('key', 'manage-training')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'manage-training',
                'name' => 'manage-training',
                'description' => 'Run Training Mode classes: trainees, Prepare Training, setup packet and training report cards',
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

        $roleId = DB::table('roles')->where('name', TrainingMode::ROLE)->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => TrainingMode::ROLE,
                'description' => 'Training Mode trainee: station work only (receiving, sorting, pallets, picking, shipping, order entry).',
                'visible_in_people_form' => false,
                'visible_in_user_admin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        foreach (DB::table('permissions')->whereIn('key', TrainingMode::TRAINING_KEYS)->pluck('id') as $id) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $setup = DB::table('pages')->where('hashtag', 'setup')->value('id');
        if ($setup && ! DB::table('menu_items')->where('link_url', '/setup/training')->exists()) {
            DB::table('menu_items')->insert([
                'page_id' => $setup, 'link_text' => 'Training Mode', 'link_url' => '/setup/training',
                'graphic_url' => '/img/help-icon.webp', 'order' => 82, 'permission_key' => 'manage-training',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $reports = DB::table('pages')->where('hashtag', 'reports')->value('id');
        if ($reports && ! DB::table('menu_items')->where('link_url', '/reports/training')->exists()) {
            DB::table('menu_items')->insert([
                'page_id' => $reports, 'link_text' => 'Training Report Cards', 'link_url' => '/reports/training',
                'graphic_url' => '/img/printing-reports.webp', 'order' => 70, 'permission_key' => 'manage-training',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menu_items')->whereIn('link_url', ['/setup/training', '/reports/training'])->delete();
        DB::table('permissions')->where('key', 'manage-training')->delete();
        DB::table('roles')->where('name', TrainingMode::ROLE)->delete();
        Schema::dropIfExists('activity_log');
        Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn('training_team'));
        Schema::table('people', fn (Blueprint $table) => $table->dropColumn(['is_trainee', 'training_team']));
        Schema::dropIfExists('training_settings');
    }
};
