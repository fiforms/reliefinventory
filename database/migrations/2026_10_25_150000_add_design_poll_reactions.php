<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Votes: instead of picking one option, a voter can like or dislike any of them (with an optional "why" each);
 * an option they don't touch stays neutral, and at least one reaction is required. design_poll_votes stays as the
 * voter's one ballot per browser (token, person, name); its single option_key/comment move to design_poll_reactions,
 * one row per option the voter reacted to. No votes had been cast when this changed, so nothing is converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_poll_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_poll_vote_id')->constrained('design_poll_votes')->cascadeOnDelete();
            $table->string('option_key', 60);
            $table->string('reaction', 10); // like | dislike
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['design_poll_vote_id', 'option_key']);
            $table->index(['option_key', 'reaction']);
        });

        Schema::table('design_poll_votes', function (Blueprint $table) {
            $table->dropIndex(['poll_slug', 'option_key']);
        });
        Schema::table('design_poll_votes', function (Blueprint $table) {
            $table->dropColumn(['option_key', 'comment']);
        });
    }

    public function down(): void
    {
        Schema::table('design_poll_votes', function (Blueprint $table) {
            $table->string('option_key', 60)->default('')->after('poll_slug');
            $table->text('comment')->nullable()->after('voter_name');
            $table->index(['poll_slug', 'option_key']);
        });
        Schema::dropIfExists('design_poll_reactions');
    }
};
