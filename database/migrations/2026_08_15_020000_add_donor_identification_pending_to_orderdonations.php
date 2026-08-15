<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A disaster-response donation sometimes arrives with no identifying
     * information at all — not even an organization name. It still has to
     * be received and sorted (never block intake on missing info — see
     * pallet-container-model), but staff need a way to flag "we don't know
     * who this is from yet" so it can be found and traced back to a donor
     * later, once known.
     *
     * Deliberately a plain boolean flag, not a status: unlike itemtypes'
     * sort_hold (which withholds an item from order forms until reviewed),
     * an unidentified donation's goods are real, in stock, and usable
     * immediately — this flag is a follow-up reminder for donor-relationship
     * tracking, not a gate on any other workflow.
     */
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->boolean('donor_identification_pending')->default(false)->after('person_id');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('donor_identification_pending');
        });
    }
};
