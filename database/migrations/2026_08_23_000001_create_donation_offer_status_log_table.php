<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only "who did what when" trail for a donation offer's
 * lifecycle — mirrors FeedbackReportStatusLog exactly. One row per
 * transition (including the initial "offered" row, from_status null), so
 * the receiving clerk can see the full conversation history behind an
 * arriving shipment: every call, decision, and note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_offer_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_offer_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            // Server-set from Auth::id(), never client-supplied.
            $table->foreignId('changed_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('contact_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_offer_status_log');
    }
};
