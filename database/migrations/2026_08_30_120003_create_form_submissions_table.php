<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            // Two-state approval, matching the pending/approved/denied
            // shape from the Facility approval design (blocked doesn't
            // apply — a submission isn't an ongoing entity that can later
            // misbehave). Null/not-applicable when the form's
            // requires_approval is false — pure data collection has no
            // approval concept, just an optional "reviewed" acknowledgement
            // via reviewed_at below.
            $table->string('approval_status')->nullable();
            $table->foreignId('reviewed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Set when a logged-in staffer filled this out on someone's
            // behalf; null for a public, unauthenticated submission (whose
            // own identity lives in the submitter_* columns instead).
            $table->foreignId('submitted_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('submitter_name')->nullable();
            $table->string('submitter_email')->nullable();
            $table->string('submitter_phone')->nullable();
            $table->string('ip_address')->nullable();
            // Set on approval when on_approval_action creates or links a
            // real Person (e.g. the new/matched Partner org record).
            $table->foreignId('linked_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
