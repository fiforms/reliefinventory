<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Print Labels (/reports/labels) is deleted (PROJECT_ANALYSIS.md Part 12,
 * permissions-model-rework-2026-09-02 memory) — an old design that printed
 * labels from a standalone catalog-browsing page disconnected from any
 * intake. Labels are attached to when you need them: printed either from
 * an existing donation's own Labels step (ReceivingController::
 * createPallets, unchanged) or ahead of time via the new Pre-print Labels
 * action inside Receiving (ReceivingController::preprintLabels +
 * attachPreprintedPallets), never from a separate top-level page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu_items')->where('link_url', '/reports/labels')->delete();
    }

    public function down(): void
    {
        DB::table('menu_items')->updateOrInsert(
            ['link_url' => '/reports/labels'],
            ['page_id' => 2, 'link_text' => 'Print Labels', 'permission_key' => 'manage-items', 'order' => 10]
        );
    }
};
