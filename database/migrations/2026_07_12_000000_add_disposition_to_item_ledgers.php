<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track what happened to goods as they were sorted.
     *
     * usable   - counted into inventory (the only value stock-on-hand includes)
     * outdated - expired before/on arrival; discarded. Tracked separately from
     *            'trashed' because it is the clearest donor-quality signal.
     * trashed  - damaged or otherwise unusable; discarded
     * diverted - usable but not needed here; passed to another organization
     *
     * Donor quality reports count outdated + trashed against the donor.
     */
    public function up(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->enum('disposition', ['usable', 'outdated', 'trashed', 'diverted'])
                ->default('usable')
                ->after('qty_subtracted');
        });
    }

    public function down(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->dropColumn('disposition');
        });
    }
};
