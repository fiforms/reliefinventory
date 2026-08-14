<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Container — the tier below Pallet in the container hierarchy:
 * everything hand-liftable (box, bin, bag) that doesn't need a pallet jack
 * or forklift to move. Containment is one-directional and structural: a
 * Container can sit on a Pallet (nullable pallet_id), but nothing points
 * the other way — Pallet is the largest container in the warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_type_id')->constrained('container_types')->restrictOnDelete();
            $table->foreignId('pallet_id')->nullable()->constrained('pallets')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('containers');
        Schema::dropIfExists('container_types');
    }
};
