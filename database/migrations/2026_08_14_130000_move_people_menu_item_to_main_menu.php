<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

// Customer & Donor Info is day-to-day operations, not configuration — the
// Setup Menu is reserved for system settings and site configuration.
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '/setup/people')->update([
            'page_id' => Page::where('hashtag', 'main')->first()->id,
            'order' => 50,
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/people')->update([
            'page_id' => Page::where('hashtag', 'setup')->first()->id,
            'order' => 10,
        ]);
    }
};
