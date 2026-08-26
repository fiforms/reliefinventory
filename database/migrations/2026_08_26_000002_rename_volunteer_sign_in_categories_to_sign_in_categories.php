<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The "volunteer_" prefix was never accurate — this list already backs the
 * non-volunteer "Other category" picker on the confirm-in screen, and is
 * becoming the per-location Guest-type picker too (see the next migration).
 * Straight rename, no data change; volunteer_sign_ins.other_category_id's
 * foreign key follows the rename automatically (InnoDB tracks the
 * constraint against the table's internal id, not its name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('volunteer_sign_in_categories', 'sign_in_categories');
    }

    public function down(): void
    {
        Schema::rename('sign_in_categories', 'volunteer_sign_in_categories');
    }
};
