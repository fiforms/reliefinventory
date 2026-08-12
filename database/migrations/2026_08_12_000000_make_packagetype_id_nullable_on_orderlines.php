<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Packaging (box/case/each) is chosen at sorting time, not at order
     * request time -- order lines only carry the requested item type and
     * quantity, so packagetype_id must be optional here.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE orderlines MODIFY packagetype_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orderlines MODIFY packagetype_id BIGINT UNSIGNED NOT NULL');
    }
};
