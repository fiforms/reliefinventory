<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ongoing partner-org status, separate from a Form submission's one-time
 * approval_status — a submission's decision is terminal, but a Person
 * tagged Partner can later need to be blocked/reconsidered long after the
 * submission that created them is history. Mirrors the pending/approved/
 * denied/blocked shape from the Facility approval design (Part 5, not yet
 * built) — this is the same idea applied to the party record that actually
 * exists today. Null for anyone not being tracked as a partner at all
 * (most donors/staff never touch this column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('partner_status')->nullable()->after('is_volunteer');
        });

        Schema::create('person_partner_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('changed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('form_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_partner_status_log');
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('partner_status');
        });
    }
};
