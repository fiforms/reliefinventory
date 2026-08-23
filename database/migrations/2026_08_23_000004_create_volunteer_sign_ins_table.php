<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The facility sign-in kiosk record (PROJECT_ANALYSIS.md Part 5): one row
 * per visit, covering both the building-occupancy roster and the
 * FEMA-reportable Volunteer subset of it. No signature field — researched
 * directly against FEMA's own documentation requirements (DAP 9525.2 /
 * PAPPG donated-resources checklist, see PROJECT_ANALYSIS.md ~line 259) and
 * confirmed none is required; what's required is content (name, hours,
 * site, description) plus a periodic official certification, which
 * certified_at/certified_by_person_id below cover.
 *
 * agency and title_function are captured per-sign-in, not on Person: a
 * volunteer's agency affiliation can change across an event (with ARC one
 * week, ACS the next), so it's a fact about the visit. title_function
 * mirrors the PAPPG checklist's "title and function (required for
 * professional services)" — optional here since it only matters for
 * FEMA's labor-rate valuation of professional-service hours, not every
 * sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_sign_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();

            // 'volunteer' | 'other' — see volunteer_sign_in_categories for
            // the admin-editable expansion of 'other'.
            $table->string('category');
            $table->foreignId('other_category_id')->nullable()
                ->constrained('volunteer_sign_in_categories')->nullOnDelete();
            $table->string('other_category_text')->nullable();

            // Per-visit, not on Person — see migration doc comment above.
            $table->string('agency')->nullable();
            $table->string('title_function')->nullable();

            $table->string('work_site')->nullable();
            $table->text('description_of_work')->nullable();

            // Emphasized for 'other' (unplanned/one-off), available but not
            // forced for 'volunteer' — trigger for an overdue-sign-out
            // nudge/flag rather than a fixed end-of-day cutoff.
            $table->timestamp('expected_departure_at')->nullable();

            $table->timestamp('signed_in_at');
            $table->timestamp('signed_out_at')->nullable();

            // 'open' | 'pending_confirmation' | 'closed' — a forgotten
            // sign-out is flagged pending_confirmation, not guessed; the
            // volunteer confirms/corrects at next sign-in, with a
            // manager-override backstop (see volunteer_sign_in_audit_log
            // for the trail of who resolved it and how).
            $table->string('status')->default('open');

            // The compliance-critical step: a person designated by a local
            // public official periodically certifies a batch of hours.
            // Server-set from Auth::id(), never client-supplied.
            $table->timestamp('certified_at')->nullable();
            $table->foreignId('certified_by_person_id')->nullable()
                ->constrained('people')->nullOnDelete();

            $table->timestamps();

            $table->index(['person_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_sign_ins');
    }
};
