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
        $helpMenu = Page::where('hashtag', 'help')->first();

        MenuItem::create([
            'page_id' => $helpMenu->id,
            'link_text' => 'Sorting',
            'link_url' => '/help/sorting',
            'graphic_url' => '/img/donation-sorting-icon.webp',
            'order' => 20,
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/help/sorting')->delete();
    }
};
