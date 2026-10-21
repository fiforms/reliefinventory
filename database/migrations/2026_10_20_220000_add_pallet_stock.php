<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-pallet stock. The ledger stays the pooled source of truth for on-hand; a warehouse (W) pallet's contents are
 * derived from two append-only sources: assignments (stock put on the pallet as it leaves sorting) and ledger rows
 * that name a pallet (`stock_pallet_id` — a fill picked from it, or, as an optional add-on, sorting straight onto it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pallet_stock_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained('pallets')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->integer('qty'); // signed: negative is a correction
            $table->unsignedBigInteger('person_id_user')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['pallet_id', 'item_id']);
        });

        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->foreignId('stock_pallet_id')->nullable()->after('pallet_id')->constrained('pallets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_pallet_id');
        });
        Schema::dropIfExists('pallet_stock_assignments');
    }
};
