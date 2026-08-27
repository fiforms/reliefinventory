<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOL (Bill of Lading) generation for Filled orders — see the
 * order-fulfillment-lifecycle-design notes: special_instructions is
 * customer-entered delivery instructions (gate codes, dock location,
 * contact-on-arrival), deliberately separate from other_needs (which is
 * additional requested items, not delivery guidance). bol_number is
 * assigned once, the first time a BOL is generated for an order, and
 * reused on every reprint after that (same idempotent-on-reprint pattern
 * as Receiving's pallet labels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->text('special_instructions')->nullable()->after('other_needs');
            $table->string('bol_number')->nullable()->after('special_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn(['special_instructions', 'bol_number']);
        });
    }
};
