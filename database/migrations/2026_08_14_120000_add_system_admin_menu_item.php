<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setupMenu = Page::where('hashtag', 'setup')->first();

        // permission_key makes MenuController drop the tile entirely for
        // anyone without admin-system — hidden, not grayed out.
        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'System Administration',
            'link_url' => '/setup/system',
            'graphic_url' => '/img/settings-icon.webp',
            'order' => 60,
            'permission_key' => 'admin-system',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/system')->delete();
    }
};
