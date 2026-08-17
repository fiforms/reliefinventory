<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporter-set flag, surfaced in the developer notification email
     * subject and the triage list. No differentiated notification routing
     * yet (e.g. SMS/paging for urgent reports) — just the flag for now,
     * so that can be layered on later without another migration.
     */
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->boolean('urgent')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropColumn('urgent');
        });
    }
};
