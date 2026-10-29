<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Votes' "Have another idea?" box (DesignVoteController::storeIdea): free text sent from a vote page, about that
 * question or anything else. Kept apart from votes so one browser can send several, with or without voting, even
 * once voting has closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_poll_ideas', function (Blueprint $table) {
            $table->id();
            $table->string('poll_slug', 60);
            $table->text('idea');
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('voter_name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['poll_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_poll_ideas');
    }
};
