<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only "who changed what, when" trail for a volunteer_sign_ins row —
 * this is the tamper-evidence half of the FEMA 2 CFR §200.336 "cannot be
 * altered" requirement (see PROJECT_ANALYSIS.md ~line 259). Corrections
 * (a pending_confirmation resolved, a manager override, an admin edit to
 * agency/hours/etc.) write a new row here; the sign-in row itself is never
 * silently overwritten without a trace. One row per changed field, not per
 * save, so a single edit touching several fields is fully legible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_sign_in_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_sign_in_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            // Nullable: the day-after-window auto-deactivation job and
            // other system-driven changes have no acting person.
            $table->foreignId('changed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_sign_in_audit_log');
    }
};
