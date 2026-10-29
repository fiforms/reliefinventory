<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

// A main-menu Help tile again (removed in 2026_10_22_120000_remove_help_menu), now
// opening /help — a list of every guide with "Print all guides" — rather than a
// sub-menu page. The per-page (?) buttons stay. No permission_key: the guides are
// visible to everyone signed in.
return new class extends Migration
{
    public function up(): void
    {
        $mainMenu = Page::where('hashtag', 'main')->first();
        if (! $mainMenu || MenuItem::where('link_url', '/help')->exists()) {
            return;
        }

        // Between Reports (70) and Setup (90).
        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Help',
            'link_url' => '/help',
            'graphic_url' => '/img/help-icon.webp',
            'order' => 85,
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/help')->delete();
    }
};
