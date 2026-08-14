<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pickup-stream thresholds (Goodwill, recycler, disposal, ...) — deliberately
 * NOT on pallets or people, per the pallet-container-model design: a stream
 * is "destination partner + warehouse + what's counted + optional threshold".
 * Orders have no threshold concept, so this stays scoped to non-order
 * outbound streams only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. "Goodwill bin pickup", "Recycler", "Disposal"
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            // What counts toward this stream's threshold: a pallet
            // kind+status (e.g. H pallets with status=ready), optionally
            // narrowed by condition (e.g. Q pallets with condition=condemned
            // for the recycler stream).
            $table->enum('counts_kind', ['R', 'W', 'S', 'H', 'Q'])->nullable();
            $table->string('counts_status')->nullable();
            $table->enum('counts_condition', ['pending', 'good', 'condemned'])->nullable();

            $table->unsignedInteger('threshold')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streams');
    }
};
