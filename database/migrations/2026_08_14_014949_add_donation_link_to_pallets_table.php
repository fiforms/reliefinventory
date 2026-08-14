<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Receiving (R) pallet to the donation it belongs to — the
 * asymmetric status rollup (Transaction::syncStatusFromPallets()) needs to
 * know every pallet a donation owns up front, not just infer it from
 * sorted item_ledger lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->foreignId('orderdonation_id')->nullable()->constrained('orderdonations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('orderdonation_id');
        });
    }
};
