<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lightweight directory for drivers who bring donations/deliveries —
 * deliberately NOT the People table: drivers aren't staff, donors, or
 * customers by default, and don't need permissions/roles. Carries `carrier`
 * so repeat trucking-company drivers can be searched/sorted by carrier.
 * `person_id` links a driver to their own Person record for the case where
 * the driver is also the donor (a walk-up personal-vehicle donation) — see
 * the "same as driver" action on Receiving.vue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('carrier')->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
