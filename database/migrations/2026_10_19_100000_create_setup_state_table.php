<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton row (always id=1) backing the first-run setup wizard (/install).
     *
     * completed_at is a one-way latch: once set the wizard never reopens, even
     * if every administrator is later deleted or demoted — otherwise "no admin
     * exists" would hand a working instance to whoever loads the page first.
     * An instance that already has an Administrator when this migration runs
     * (every existing install) is latched immediately.
     */
    public function up(): void
    {
        Schema::create('setup_state', function (Blueprint $table) {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('demo_data_seeded_at')->nullable();
            $table->timestamps();
        });

        $administratorRoleId = DB::table('roles')->where('name', 'Administrator')->value('id');
        $hasAdministrator = $administratorRoleId
            && DB::table('people_roles')->where('role_id', $administratorRoleId)->exists();

        DB::table('setup_state')->insert([
            'id' => 1,
            'completed_at' => $hasAdministrator ? now() : null,
            'demo_data_seeded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_state');
    }
};
