<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Truck — the top tier of the container hierarchy (pallet-container-model).
 * Received the moment a load is dropped off, before it's unloaded, so it
 * shows up on the (future) Receiving dashboard as "waiting to be sorted"
 * and can't be forgotten sitting in a parking lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->enum('status', ['received', 'unloaded'])->default('received');
            $table->string('trailer_number')->nullable();
            $table->unsignedInteger('rough_pallet_estimate')->nullable();
            $table->text('contents_summary')->nullable();
            // Shipment-level, advisory, never derived into a per-pallet count.
            $table->decimal('manifest_weight_lbs', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trucks');
    }
};
