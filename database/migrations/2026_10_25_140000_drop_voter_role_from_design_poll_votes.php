<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Design Votes no longer asks "I am a…" — one less thing on the page. No votes had been cast when it was removed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_poll_votes', function (Blueprint $table) {
            $table->dropColumn('voter_role');
        });
    }

    public function down(): void
    {
        Schema::table('design_poll_votes', function (Blueprint $table) {
            $table->string('voter_role', 30)->nullable()->after('voter_name');
        });
    }
};
