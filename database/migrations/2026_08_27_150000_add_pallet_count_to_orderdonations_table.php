<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            // Staff-entered when Filling completes (OrderFillingController::
            // completeFilling) — how many pallets the packed order actually
            // took, shown to the driver on the Driver Portal and printed on
            // the BOL. Distinct from the BOL's separate "confirmed by
            // driver" pallet-count line, which stays a blank the driver
            // fills in by hand on delivery.
            $table->unsignedSmallInteger('pallet_count')->nullable()->after('special_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('pallet_count');
        });
    }
};
