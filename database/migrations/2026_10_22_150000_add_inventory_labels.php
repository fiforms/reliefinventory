<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory labels: one label per pallet_stock_assignments row (item + qty put on a pallet), numbered from that row's id
 * (see App\Support\InventoryLabelCode). A label shows the earliest expiration of *what it covers*, but expiration was only
 * stored once per pallet (pallets.earliest_expiry), so each assignment now carries its own, nullable (null = no date known).
 * Existing rows stay null: their date can't be recovered per item. Inventory labels print on their own media, defaulting to
 * the small label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallet_stock_assignments', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('qty');
        });

        Schema::table('label_settings', function (Blueprint $table) {
            $table->string('inventory_label_media', 40)->default('label_small')->after('pallet_label_media');
        });
    }

    public function down(): void
    {
        Schema::table('label_settings', function (Blueprint $table) {
            $table->dropColumn('inventory_label_media');
        });
        Schema::table('pallet_stock_assignments', function (Blueprint $table) {
            $table->dropColumn('expires_on');
        });
    }
};
