<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * The generic form-builder admin page. Submission review lives at
 * /setup/forms/{id}/submissions, reached from within Forms.vue rather than
 * a second top-level menu item — same "no new nav item" choice as Donation
 * Offers living inside Receiving.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setupMenu = Page::where('hashtag', 'setup')->first();

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Forms & Surveys',
            'link_url' => '/setup/forms',
            'graphic_url' => '/img/settings-icon.webp',
            'order' => 71,
            'group_label' => 'System Administration',
            'permission_key' => 'manage-forms',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/forms')->delete();
    }
};
