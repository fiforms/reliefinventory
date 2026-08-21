<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately orthogonal to the existing `category` enum, not a new
     * value inside it: category already governs pipeline routing (only
     * "donation" rows get pallets/sorting; supplies/equipment/other don't).
     * Both the MachForms and Flowtrac reviews found real intake data
     * distinguishes donated goods from purchased/procured restock supply
     * that still needs full pallet/sorting handling — it just shouldn't
     * count toward donor-quality reporting the way a real donation does.
     * Applicable only when category = 'donation'.
     */
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->enum('donation_source_type', ['donated', 'purchased', 'unknown'])
                ->nullable()
                ->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('donation_source_type');
        });
    }
};
