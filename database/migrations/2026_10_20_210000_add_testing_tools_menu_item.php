<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Testing Tools (warehouse simulator + slow-query log) sits in the System Administration group, right after Updates & Backups. */
    public function up(): void
    {
        $sibling = MenuItem::where('link_url', '/setup/system')->first();
        if (! $sibling || MenuItem::where('link_url', '/setup/testing')->exists()) {
            return;
        }

        MenuItem::create([
            'page_id' => $sibling->page_id,
            'link_text' => 'Testing Tools',
            'link_url' => '/setup/testing',
            'graphic_url' => $sibling->graphic_url,
            'order' => $sibling->order + 1,
            'group_label' => $sibling->group_label,
            'permission_key' => 'admin-system',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/testing')->delete();
    }
};
