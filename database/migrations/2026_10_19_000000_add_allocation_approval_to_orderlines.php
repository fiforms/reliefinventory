<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved (allocated) quantity per order line (feedback #50). When an item
 * is short across Ready to Fill orders, a manager approves how much of each
 * request the warehouse will actually pick; pickers then see only that
 * number ("Qty to Pick" — qty_approved, falling back to qty_requested when
 * nothing was approved). qty_requested is never changed, so requested vs.
 * approved vs. filled stays available for "requested but not fulfilled"
 * reporting. approved_by_person_id is an actor field, set from Auth::id()
 * server-side, never fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderlines', function (Blueprint $table) {
            $table->unsignedInteger('qty_approved')->nullable()->after('qty_requested');
            $table->foreignId('approved_by_person_id')->nullable()->after('qty_approved')
                ->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_person_id');
        });
    }

    public function down(): void
    {
        Schema::table('orderlines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_person_id');
            $table->dropColumn(['qty_approved', 'approved_at']);
        });
    }
};
