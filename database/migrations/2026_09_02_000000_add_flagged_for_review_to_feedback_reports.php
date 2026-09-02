<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Non-blocking flag set by FeedbackContentScanner on submit/update —
     * mirrors donor_identification_pending on Transaction: a find-it-later
     * reminder for a human triager, never a gate on submission or triage
     * itself. flagged_reason records which pattern matched, so the triager
     * doesn't have to re-scan the message themselves.
     */
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->boolean('flagged_for_review')->default(false)->after('urgent');
            $table->string('flagged_reason')->nullable()->after('flagged_for_review');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropColumn(['flagged_for_review', 'flagged_reason']);
        });
    }
};
