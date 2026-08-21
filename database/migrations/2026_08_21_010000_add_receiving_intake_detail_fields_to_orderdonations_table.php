<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional dock-side intake fields from the Receiving page/Help review
 * (ReviewWithTim/Receiving.rtf): driver contact, how the load arrived,
 * whether it arrived already palletized, and a general "where did this
 * come from" location captured on the donation itself rather than ever
 * overwriting the shared Unknown Donor person's address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('driver_name')->nullable()->after('manifest_weight_lbs');
            $table->string('driver_phone')->nullable()->after('driver_name');

            $table->enum('arrival_method', ['semi', 'box_truck', 'personal_vehicle', 'other'])
                ->nullable()->after('driver_phone');

            // Informational only — never gates the container_count field.
            // A warehouse that runs on pallets internally will likely still
            // palletize a non-palletized arrival on the way to sorting.
            $table->boolean('arrived_palletized')->nullable()->after('arrival_method');

            // Always-visible "where did this come from" text, independent
            // of donor_identification_pending. Lives with the donation, not
            // the donor Person, so re-using the Unknown Donor record never
            // clobbers a previous donation's address.
            $table->text('source_location')->nullable()->after('arrived_palletized');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn(['driver_name', 'driver_phone', 'arrival_method', 'arrived_palletized', 'source_location']);
        });
    }
};
