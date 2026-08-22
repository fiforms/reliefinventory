<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

// Site Banner editor split out of the Feedback & Bug Reports page into its
// own screen (/setup/banner) — same permission (manage-feedback) as the
// /json/banner-settings endpoint it posts to.
return new class extends Migration
{
    public function up(): void
    {
        $setupMenu = Page::where('hashtag', 'setup')->first();

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Site Banner',
            'link_url' => '/setup/banner',
            'graphic_url' => '/img/settings-icon.webp',
            'order' => 68,
            'group_label' => 'System Administration',
            'permission_key' => 'manage-feedback',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/banner')->delete();
    }
};
