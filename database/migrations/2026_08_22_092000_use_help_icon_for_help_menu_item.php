<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

// The main-menu Help tile was pointed at reports.webp (a copy-paste leftover
// from the Reports tile) instead of the dedicated help-icon.webp.
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '#help')->update([
            'graphic_url' => '/img/help-icon.webp',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '#help')->update([
            'graphic_url' => '/img/reports.webp',
        ]);
    }
};
