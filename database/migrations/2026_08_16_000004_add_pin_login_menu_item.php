<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Gated at the menu-item level on admin-system (the common case, and
     * MenuController only supports one permission_key per item — no OR
     * primitive). A person granted only the narrower manage-trusted-devices
     * permission (without admin-system) can still reach the page directly
     * at /setup/pin-login — that route itself checks general-access and
     * shows only the sections each visitor actually has permission for —
     * they just won't see a menu tile for it. Acceptable: that's the rarer,
     * more specialized delegation case.
     */
    public function up(): void
    {
        $setupMenu = Page::where('hashtag', 'setup')->first();

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'PIN Login',
            'link_url' => '/setup/pin-login',
            'graphic_url' => '/img/edit-padlock-icon.webp',
            'order' => 65,
            'permission_key' => 'admin-system',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/pin-login')->delete();
    }
};
