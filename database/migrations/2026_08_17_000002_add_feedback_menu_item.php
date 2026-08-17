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

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Feedback & Bug Reports',
            'link_url' => '/setup/feedback',
            'graphic_url' => '/img/settings-icon.webp',
            'order' => 70,
            'permission_key' => 'manage-feedback',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/feedback')->delete();
    }
};
