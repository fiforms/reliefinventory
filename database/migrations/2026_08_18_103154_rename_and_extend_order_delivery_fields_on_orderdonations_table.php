<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review & Confirm screen refinement: "Time Window" was really a delivery-
 * only preferred time, so it's renamed to make that explicit, and gets a
 * companion field for which days of the week the customer can accept
 * delivery (null/empty = "Any Day", the default). Both are meaningless for
 * pickup — the warehouse controls pickup days/times, not the customer — so
 * OrderController::complete() force-clears them whenever
 * fulfillment_method is "pickup", regardless of what's submitted.
 * needed_by_date stays shared by both methods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->renameColumn('needed_time_window', 'preferred_time');
        });
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->json('delivery_days')->nullable()->after('needed_by_date');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('delivery_days');
        });
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->renameColumn('preferred_time', 'needed_time_window');
        });
    }
};
