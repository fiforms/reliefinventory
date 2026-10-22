<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A donor's standing (preferred / normal / watch / decline) is a human decision the Donor Report only *suggests*:
 * a stored value on the person, plus an append-only log of every change (who, when, why). It never gates anything —
 * Receiving just shows it — so it is a plain string column validated in code, not a status lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('donor_standing', 20)->default('normal');
            $table->text('donor_standing_note')->nullable();
            $table->unsignedBigInteger('donor_standing_set_by')->nullable();
            $table->timestamp('donor_standing_set_at')->nullable();
        });

        Schema::create('donor_standing_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('from_standing', 20);
            $table->string('to_standing', 20);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('person_id_user')->nullable();
            $table->timestamps();
            $table->index('person_id');
        });

        // Its own permission (mirrors PermissionsSeeder): Office and Administrator get it by default.
        $permissionId = DB::table('permissions')->where('key', 'manage-donor-standing')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'manage-donor-standing',
                'name' => 'manage-donor-standing',
                'description' => "Set a donor's standing (preferred / normal / watch / decline) from the Donor Report",
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
        DB::table('permissions')->where('key', 'manage-donor-standing')->delete();
        Schema::dropIfExists('donor_standing_log');
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['donor_standing', 'donor_standing_note', 'donor_standing_set_by', 'donor_standing_set_at']);
        });
    }
};
