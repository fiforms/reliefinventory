<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * Relocates the "Volunteer Kiosk" tile from the main menu (where it was
 * placed between People and Reports, order 65) into Setup's System
 * Administration group, alongside the other admin/config tiles — the
 * kiosk device itself is launched from here, not from day-to-day
 * warehouse navigation. Placed between PIN Login (60) and Site Banner
 * (65), since kiosk mode builds on the same trusted-device/PIN
 * infrastructure.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setupMenu = Page::where('hashtag', 'setup')->first();

        MenuItem::where('link_url', '/volunteers/kiosk')->update([
            'page_id' => $setupMenu->id,
            'group_label' => 'System Administration',
            'order' => 63,
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/volunteers/kiosk')->update([
            'page_id' => 1,
            'group_label' => null,
            'order' => 65,
        ]);
    }
};

