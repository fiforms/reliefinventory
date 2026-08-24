<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Reverses 2026_08_24_120000_add_auto_enable_to_kiosk_menu_item — the
 * enable-confirmation now happens on the Dashboard itself (Dashboard.vue
 * special-cases this exact URL and shows KioskEnableConfirmModal in place,
 * only navigating here after confirmation) rather than by auto-opening a
 * modal after landing on this page, so the query string on the menu item's
 * own link_url is no longer needed — and having it there would just make
 * Dashboard.vue's plain-URL match on this tile fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '/volunteers/kiosk?enable=1')->update([
            'link_url' => '/volunteers/kiosk',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/volunteers/kiosk')->update([
            'link_url' => '/volunteers/kiosk?enable=1',
        ]);
    }
};
