<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * The new Kiosk Settings admin page (locations, guest types, agency/task
 * suggestions, idle-reset) — placed right after the "Volunteer Kiosk"
 * launch tile (order 63) in Setup's System Administration group, since
 * configuring the kiosk naturally sits next to launching it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kioskTile = MenuItem::where('link_url', 'like', '/volunteers/kiosk%')->first();

        MenuItem::create([
            'page_id' => $kioskTile->page_id,
            'link_text' => 'Kiosk Settings',
            'link_url' => '/setup/kiosk-settings',
            'graphic_url' => '/img/edit-users-icon.webp',
            'order' => 64,
            'group_label' => $kioskTile->group_label,
            'permission_key' => 'admin-system',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/kiosk-settings')->delete();
    }
};
