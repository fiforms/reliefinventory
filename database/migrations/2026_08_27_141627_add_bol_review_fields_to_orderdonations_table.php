<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manager sign-off step on top of Delivered: staff review the driver's
 * uploaded signed BOL and either approve it (Delivered -> Completed, the
 * order's real terminus now) or reject it (back to Shipped — the driver
 * sees it again in the Driver Portal and re-uploads). bol_rejection_reason
 * is shown to the driver so they know what to fix; overwritten on each
 * reject cycle, not an append-only log — this app only keeps a full
 * status-change history table where an earlier design specifically called
 * for it (FeedbackReportStatusLog, DonationOfferStatusLog), and this
 * doesn't need one. bol_reviewed_by_person_id is an actor field — set
 * directly from Auth::id() server-side, never fillable (see CLAUDE.md's
 * actor-field convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->text('bol_rejection_reason')->nullable()->after('signed_bol_path');
            $table->foreignId('bol_reviewed_by_person_id')->nullable()->after('bol_rejection_reason')
                ->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bol_reviewed_by_person_id');
            $table->dropColumn('bol_rejection_reason');
        });
    }
};
