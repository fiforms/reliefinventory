<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Building-safety design pass (2026-08-23): "who's currently in the
 * building" is a computed fact derived from volunteer_sign_ins, not a
 * stored one — a stale open sign-in from a prior day should never be
 * assumed to still be an occupant. building_closeouts is the reset:
 * confirming the building empty (e.g. end of day) instantly stops every
 * currently-open sign-in from counting as an occupant from that moment on,
 * without touching a single one of those rows — the hours record itself
 * (status/signed_out_at) stays exactly as it was, correctable later. See
 * VolunteerSignIn::scopeOccupying().
 *
 * building_roll_calls covers the fire-safety headcount case: a snapshot of
 * who was occupying the building when the roll call started (frozen so
 * the list doesn't shift mid-count), worked independently by however many
 * people/gathering areas are doing headcount, each marking names off the
 * same shared confirmation list — no live sync needed, "who's not
 * accounted for" is just the snapshot minus everyone confirmed so far,
 * computed on demand whenever someone checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_closeouts', function (Blueprint $table) {
            $table->id();
            $table->timestamp('closed_at');
            // Resolved via the PIN-gated closeout action, not a live
            // session — nullable to match the rest of this app's
            // nullOnDelete actor-column convention.
            $table->foreignId('closed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('building_roll_calls', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->foreignId('started_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // The frozen roster — one row per sign-in that was occupying the
        // building at the moment the roll call started.
        Schema::create('building_roll_call_snapshot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_roll_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('volunteer_sign_in_id')->constrained()->cascadeOnDelete();
            $table->unique(['building_roll_call_id', 'volunteer_sign_in_id'], 'roll_call_snapshot_unique');
        });

        Schema::create('building_roll_call_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_roll_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('volunteer_sign_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->unique(['building_roll_call_id', 'volunteer_sign_in_id'], 'roll_call_confirmation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_roll_call_confirmations');
        Schema::dropIfExists('building_roll_call_snapshot');
        Schema::dropIfExists('building_roll_calls');
        Schema::dropIfExists('building_closeouts');
    }
};
