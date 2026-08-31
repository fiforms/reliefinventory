<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every approve/deny/reviewed-note action, same
 * pattern as donation_offer_status_log / FeedbackReportStatusLog. No
 * required note field (Facility approval design dropped that requirement) —
 * notes are optional here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submission_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('changed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submission_status_log');
    }
};
