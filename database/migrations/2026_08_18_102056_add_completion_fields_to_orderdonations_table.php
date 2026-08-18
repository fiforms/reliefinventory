<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields captured on the order-entry Review & Confirm screen when an order
 * is marked complete (see order-fulfillment-lifecycle-design memory). All
 * nullable — only meaningful for type=order rows, and only ever populated
 * once, at completion (there's no per-field autosave for these, unlike the
 * line-entry screen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->enum('fulfillment_method', ['delivery', 'pickup'])->nullable()->after('comments');
            $table->date('needed_by_date')->nullable()->after('fulfillment_method');
            $table->string('needed_time_window', 100)->nullable()->after('needed_by_date');
            $table->string('contact_name', 191)->nullable()->after('needed_time_window');
            $table->string('contact_phone', 50)->nullable()->after('contact_name');
            // Items a customer wants that aren't in the catalog — mirrors the
            // "Other Needs" free-text section on the offline order form.
            $table->text('other_needs')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_method', 'needed_by_date', 'needed_time_window',
                'contact_name', 'contact_phone', 'other_needs',
            ]);
        });
    }
};
