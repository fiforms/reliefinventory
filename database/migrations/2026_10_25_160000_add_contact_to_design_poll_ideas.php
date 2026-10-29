<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Have another idea?" can carry an optional email, and an "OK to contact me about this idea" box that needs one
 * (DesignVoteController::storeIdea).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_poll_ideas', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->after('voter_name');
            $table->boolean('ok_to_contact')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('design_poll_ideas', function (Blueprint $table) {
            $table->dropColumn(['email', 'ok_to_contact']);
        });
    }
};
