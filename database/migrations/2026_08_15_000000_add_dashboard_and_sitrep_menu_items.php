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
        $reportsMenu = Page::where('hashtag', 'reports')->first();

        MenuItem::create([
            'page_id' => $reportsMenu->id,
            'link_text' => 'Warehouse Dashboard',
            'link_url' => '/reports/dashboard',
            'graphic_url' => '/img/warehouse.webp',
            'order' => 5,
            'permission_key' => 'view-dashboard',
        ]);

        MenuItem::create([
            'page_id' => $reportsMenu->id,
            'link_text' => 'Situation Report',
            'link_url' => '/reports/sitrep',
            'graphic_url' => '/img/printing-reports.webp',
            'order' => 6,
            'permission_key' => 'view-sitrep',
        ]);
    }

    public function down(): void
    {
        MenuItem::whereIn('link_url', ['/reports/dashboard', '/reports/sitrep'])->delete();
    }
};
