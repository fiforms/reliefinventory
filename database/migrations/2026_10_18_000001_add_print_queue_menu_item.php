<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Print Queue sits in the main workflow group, right after Driver Portal.
 * Gated on print-documents: everyone who can print can see their own jobs;
 * manage-print-queue (checked server-side) widens that to everyone's.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::create([
            'page_id' => 1,
            'link_text' => 'Print Queue',
            'link_url' => '/print-queue',
            'graphic_url' => '/img/printing-reports.webp',
            'order' => 58,
            'permission_key' => 'print-documents',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/print-queue')->delete();
    }
};
