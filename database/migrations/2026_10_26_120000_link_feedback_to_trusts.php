<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback aggregation moves onto the federation trust system. A remote names the trust it sends reports
 * through (`feedback_trust_id`); central records which trust each ingested report arrived over (`trust_id`),
 * which is authoritative where the old self-asserted `instance_identifier` was not. The shared-secret columns
 * stay for one release so an existing remote keeps working until it is moved to a trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_aggregation_settings', function (Blueprint $table) {
            $table->foreignId('feedback_trust_id')->nullable()->constrained('trusts')->nullOnDelete();
        });
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->foreignId('trust_id')->nullable()->constrained('trusts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trust_id');
        });
        Schema::table('feedback_aggregation_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feedback_trust_id');
        });
    }
};
