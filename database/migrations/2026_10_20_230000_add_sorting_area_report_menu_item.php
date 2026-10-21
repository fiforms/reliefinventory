<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Sorting Area Report (sorted vs. put on pallets, what is still waiting) — after Unfulfilled Requests, same gating. */
    public function up(): void
    {
        $sibling = MenuItem::where('link_url', '/reports/orders')->first();
        if (! $sibling || MenuItem::where('link_url', '/reports/sorting-area')->exists()) {
            return;
        }

        MenuItem::create([
            'page_id' => $sibling->page_id,
            'link_text' => 'Sorting Area',
            'link_url' => '/reports/sorting-area',
            'graphic_url' => '/img/printing-reports.webp',
            'order' => 26,
            'permission_key' => 'view-reports',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/reports/sorting-area')->delete();
    }
};
