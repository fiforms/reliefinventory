<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donor-quality tracking (donated vs. purchased restock) is being dropped —
 * purchased stock is recorded as a donation from whoever purchased it, so
 * this field never carried real information distinct from `category`.
 * Reconciling any existing Flowtrac-imported records that relied on it is
 * deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('donation_source_type');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->enum('donation_source_type', ['donated', 'purchased', 'unknown'])
                ->nullable()
                ->after('category');
        });
    }
};
