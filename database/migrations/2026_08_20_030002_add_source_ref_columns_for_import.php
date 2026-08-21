<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit source-system/source-ref pair, not fuzzy name-matching, is
     * what makes repeated imports idempotent — Washington will run
     * Flowtrac and reliefinventory in parallel for a while, so the import
     * must be safely re-runnable. source_ref values look like
     * "flowtrac:account:<name>" / "flowtrac:product:<code>" — unique only
     * within a given source_system, so the pair is what's indexed/queried
     * together, not source_ref alone.
     */
    public function up(): void
    {
        foreach (['people', 'itemtypes', 'orderdonations'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('source_system')->nullable();
                $blueprint->string('source_ref')->nullable();
                $blueprint->index(['source_system', 'source_ref']);
            });
        }
    }

    public function down(): void
    {
        foreach (['people', 'itemtypes', 'orderdonations'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex([$table.'_source_system_source_ref_index']);
                $blueprint->dropColumn(['source_system', 'source_ref']);
            });
        }
    }
};
