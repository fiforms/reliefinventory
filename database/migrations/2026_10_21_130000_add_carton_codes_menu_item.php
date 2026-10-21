<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Carton Barcodes (review/remove confirmed carton -> item mappings) — beside the master item list, same gating. */
    public function up(): void
    {
        $sibling = MenuItem::where('link_url', '/setup/items')->first();
        if (! $sibling || MenuItem::where('link_url', '/setup/carton-codes')->exists()) {
            return;
        }

        MenuItem::create([
            'page_id' => $sibling->page_id,
            'link_text' => 'Carton Barcodes',
            'link_url' => '/setup/carton-codes',
            'graphic_url' => $sibling->graphic_url,
            'order' => $sibling->order + 1,
            'permission_key' => 'manage-items',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/carton-codes')->delete();
    }
};
