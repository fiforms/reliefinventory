<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Right-sized fair-share allocation (PROJECT_ANALYSIS.md Part 5), pulled
 * forward into the single-warehouse Order Filling build rather than waiting
 * on the multi-facility model: a self-reported need level per order line
 * (decision support only — never changes the proportional-allocation math,
 * per the design's explicit no-anti-gaming stance) and a per-itemtype
 * low-stock threshold override (null = global config default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderlines', function (Blueprint $table) {
            $table->enum('need_level', ['critical', 'moderate', 'low'])->nullable()->after('comments');
        });

        Schema::table('itemtypes', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('orderlines', function (Blueprint $table) {
            $table->dropColumn('need_level');
        });

        Schema::table('itemtypes', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
