<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Unfulfilled Requests Report (requested but not filled, by item, county
     * and zip) — listed right after Outstanding Orders, gated the same way.
     */
    public function up(): void
    {
        $sibling = MenuItem::where('link_url', '/reports/orders')->first();
        if (! $sibling || MenuItem::where('link_url', '/reports/unfulfilled')->exists()) {
            return;
        }

        MenuItem::create([
            'page_id' => $sibling->page_id,
            'link_text' => 'Unfulfilled Requests',
            'link_url' => '/reports/unfulfilled',
            'graphic_url' => '/img/printing-reports.webp',
            'order' => 25,
            'permission_key' => 'view-reports',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/reports/unfulfilled')->delete();
    }
};
