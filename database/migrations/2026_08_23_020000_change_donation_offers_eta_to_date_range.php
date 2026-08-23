<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the single `eta` datetime column with an `eta_start`/`eta_end`
 * date range — a phoned-in offer is never given a precise arrival time, just
 * a rough window ("sometime next week", "Tuesday or Wednesday"). A specific
 * time, when someone actually has one, belongs in transit_notes rather than
 * implying false precision in a dedicated column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_offers', function (Blueprint $table) {
            $table->date('eta_start')->nullable()->after('status');
            $table->date('eta_end')->nullable()->after('eta_start');
        });

        DB::table('donation_offers')->whereNotNull('eta')->get()->each(function ($offer) {
            DB::table('donation_offers')->where('id', $offer->id)->update([
                'eta_start' => substr($offer->eta, 0, 10),
            ]);
        });

        Schema::table('donation_offers', function (Blueprint $table) {
            $table->dropColumn('eta');
        });
    }

    public function down(): void
    {
        Schema::table('donation_offers', function (Blueprint $table) {
            $table->dateTime('eta')->nullable()->after('status');
        });

        DB::table('donation_offers')->whereNotNull('eta_start')->get()->each(function ($offer) {
            DB::table('donation_offers')->where('id', $offer->id)->update([
                'eta' => $offer->eta_start,
            ]);
        });

        Schema::table('donation_offers', function (Blueprint $table) {
            $table->dropColumn(['eta_start', 'eta_end']);
        });
    }
};
