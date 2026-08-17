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
     * Singleton settings row (always id=1), same shape as pin_login_settings
     * — one modular banner slot shown at the top of every page. Only one
     * banner can ever be active because there's only one row: `type` null
     * means nothing is shown. `version` is bumped whenever the active
     * banner's content changes, which invalidates old per-user dismissals
     * (banner_dismissals rows are keyed to a version, not "ever dismissed").
     */
    public function up(): void
    {
        Schema::create('banner_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['feedback', 'maintenance', 'message'])->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        DB::table('banner_settings')->insert([
            'id' => 1,
            'type' => null,
            'message' => null,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('banner_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('dismissed_at');
            $table->unique(['person_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_dismissals');
        Schema::dropIfExists('banner_settings');
    }
};
