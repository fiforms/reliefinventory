<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A donation offer tracks a donation from the initial phone call ("we'd
 * like to donate X") through an approve/refuse/divert decision and, once
 * accepted, an ETA-sorted wait for physical arrival — before any real
 * Transaction (orderdonations) row exists. Not every donation goes through
 * this: walk-in drop-offs skip it entirely.
 *
 * Deliberately no per-status column pairs (approved_by/at, refused_by/at,
 * ...) — see donation_offer_status_log, the single append-only audit trail
 * for every transition, following the FeedbackReportStatusLog pattern. Only
 * a small set of "latest reason" fields live here, for display without
 * reading the log; who/when for every transition lives exclusively in the
 * log table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('contact_person_id')->nullable()->constrained('people')->nullOnDelete();
            // offered -> {refused|diverted|pending -> {cancelled|received}}
            // "accepted" is the transition into pending, never a resting
            // column value — see donation_offer_status_log's from/to pair.
            $table->string('status')->default('offered');
            $table->dateTime('eta')->nullable();
            $table->text('transit_notes')->nullable();
            $table->text('refused_reason')->nullable();
            $table->text('diverted_to')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('donation_id')->nullable()->constrained('orderdonations')->nullOnDelete();
            $table->foreignId('entered_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_offers');
    }
};
