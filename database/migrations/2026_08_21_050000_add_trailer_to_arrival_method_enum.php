<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Trailer (pulled by pickup truck)" as its own arrival_method option,
 * distinct from a semi/tractor-trailer — from comparing against the real
 * MachForm Manifest form's Truck Size options (Manifest_Form_Structure_Handoff.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orderdonations MODIFY arrival_method ENUM('semi', 'box_truck', 'personal_vehicle', 'delivery_truck', 'trailer', 'other') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orderdonations MODIFY arrival_method ENUM('semi', 'box_truck', 'personal_vehicle', 'delivery_truck', 'other') NULL");
    }
};
