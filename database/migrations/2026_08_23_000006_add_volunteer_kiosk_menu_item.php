<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Placed between People (60) and Reports (70) — the kiosk is a
 * people/occupancy concern, not part of the goods-lifecycle ordering
 * (Receiving -> Sorting -> Inventory -> Order Entry/Filling) that the rest
 * of the main menu follows. Reuses edit-users-icon.webp as a placeholder;
 * no dedicated kiosk icon exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::create([
            'page_id' => 1,
            'link_text' => 'Volunteer Kiosk',
            'link_url' => '/volunteers/kiosk',
            'graphic_url' => '/img/edit-users-icon.webp',
            'order' => 65,
            'permission_key' => 'operate-volunteer-kiosk',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/volunteers/kiosk')->delete();
    }
};
