<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Email Settings — in System Administration, just before Updates & Backups, same gating. */
    public function up(): void
    {
        $sibling = MenuItem::where('link_url', '/setup/system')->first();
        if (! $sibling || MenuItem::where('link_url', '/setup/email')->exists()) {
            return;
        }

        MenuItem::create([
            'page_id' => $sibling->page_id,
            'link_text' => 'Email Settings',
            'link_url' => '/setup/email',
            'graphic_url' => $sibling->graphic_url,
            'order' => $sibling->order - 1,
            'group_label' => $sibling->group_label,
            'permission_key' => 'admin-system',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/email')->delete();
    }
};
