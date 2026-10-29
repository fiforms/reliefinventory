<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

// Help guides are now reached from a (?) button on each page they describe
// (HelpReportController::PAGE_GUIDES), so the main-menu Help tile and its
// sub-menu page (added in 2026_08_18_170000_add_help_menu) are removed.
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '#help')->delete();
        $helpPages = Page::where('hashtag', 'help')->pluck('id');
        MenuItem::whereIn('page_id', $helpPages)->delete();
        Page::whereIn('id', $helpPages)->delete();
    }

    public function down(): void
    {
        $mainMenu = Page::where('hashtag', 'main')->first();
        if (! $mainMenu) {
            return;
        }

        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Help',
            'link_url' => '#help',
            'graphic_url' => '/img/help-icon.webp',
            'order' => 85,
        ]);

        $help = Page::create([
            'hashtag' => 'help',
            'menu_title' => 'Help',
            'header_text' => 'Step-by-Step Guides',
            'content' => 'Pick a stage of the warehouse workflow for a walkthrough of that page.',
        ]);

        foreach ([['Receiving', '/help/receiving', '/img/donation-entry-icon.webp', 10],
            ['Sorting', '/help/sorting', '/img/donation-sorting-icon.webp', 20],
            ['Return', '#main', '/img/back-arrow.webp', 99]] as [$text, $url, $img, $order]) {
            MenuItem::create(['page_id' => $help->id, 'link_text' => $text, 'link_url' => $url, 'graphic_url' => $img, 'order' => $order]);
        }
    }
};
