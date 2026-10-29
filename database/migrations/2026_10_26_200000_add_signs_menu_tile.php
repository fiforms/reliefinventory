<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

// A Signs item on the Setup menu (SignController, /setup/signs): ready-made or typed warehouse signs on
// Letter paper. No permission_key — like Help, it is open to everyone signed in, so anyone
// who can open the Setup menu sees it.
return new class extends Migration
{
    public function up(): void
    {
        $setup = Page::where('hashtag', 'setup')->first();
        if (! $setup || MenuItem::where('link_url', '/setup/signs')->exists()) {
            return;
        }

        // Between Trust Console (68) and Feedback & Bug Reports (70).
        MenuItem::create([
            'page_id' => $setup->id,
            'link_text' => 'Signs',
            'link_url' => '/setup/signs',
            'graphic_url' => '/img/signs-icon.webp',
            'order' => 69,
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/signs')->delete();
    }
};
