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
        $mainMenu = Page::where('hashtag', 'main')->first();

        // Sits between Reports (order 80) and Setup (order 90) on the main menu.
        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Help',
            'link_url' => '#help',
            'graphic_url' => '/img/reports.webp',
            'order' => 85,
        ]);

        // No permission_key on the Help page itself or its items — how-to
        // guides should be visible to everyone authenticated, matching
        // MenuController::index's "null permission_key = visible to
        // everyone" rule.
        $helpMenu = Page::create([
            'hashtag' => 'help',
            'menu_title' => 'Help',
            'header_text' => 'Step-by-Step Guides',
            'content' => 'Pick a stage of the warehouse workflow for a walkthrough of that page.',
        ]);

        MenuItem::create([
            'page_id' => $helpMenu->id,
            'link_text' => 'Receiving',
            'link_url' => '/help/receiving',
            'graphic_url' => '/img/donation-entry-icon.webp',
            'order' => 10,
        ]);

        MenuItem::create([
            'page_id' => $helpMenu->id,
            'link_text' => 'Return',
            'link_url' => '#main',
            'graphic_url' => '/img/back-arrow.webp',
            'order' => 99,
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/help/receiving')->delete();
        MenuItem::where('link_url', '#help')->delete();
        MenuItem::whereIn('page_id', Page::where('hashtag', 'help')->pluck('id'))->delete();
        Page::where('hashtag', 'help')->delete();
    }
};
