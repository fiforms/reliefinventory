<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training Mode's reset point (App\Services\TrainingReset): the database-only backup zip taken just before the mode is
 * turned on, which "Turn off and reset" restores. Set after the backup is taken, so a reset leaves these blank again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->string('reset_point_file')->nullable();
            $table->timestamp('reset_point_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->dropColumn(['reset_point_file', 'reset_point_at']);
        });
    }
};
