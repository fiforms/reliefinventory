<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Delivery Truck (UPS/FedEx/Amazon)" as its own arrival_method option, with
 * a carrier text field to name which one — separate from arrival_method_other
 * since delivery-truck arrivals aren't really "other", just need a carrier name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('carrier')->nullable()->after('arrival_method_other');
        });

        DB::statement("ALTER TABLE orderdonations MODIFY arrival_method ENUM('semi', 'box_truck', 'personal_vehicle', 'delivery_truck', 'other') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orderdonations MODIFY arrival_method ENUM('semi', 'box_truck', 'personal_vehicle', 'other') NULL");

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('carrier');
        });
    }
};
