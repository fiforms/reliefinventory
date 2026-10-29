<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Instance Admin (updates, offline mode, whole-instance backups) gets its own tile, shown to instance administrators only
     * (permission_key 'instance-admin' — see MenuController), and Updates & Backups becomes Warehouse Backups.
     */
    public function up(): void
    {
        $system = MenuItem::where('link_url', '/setup/system')->first();
        if (! $system) {
            return;
        }

        $system->update(['link_text' => 'Warehouse Backups']);

        if (! MenuItem::where('link_url', '/setup/instance')->exists()) {
            MenuItem::create([
                'page_id' => $system->page_id,
                'link_text' => 'Instance Admin',
                'link_url' => '/setup/instance',
                'graphic_url' => $system->graphic_url,
                'order' => $system->order - 1,
                'group_label' => $system->group_label,
                'permission_key' => 'instance-admin',
            ]);
        }
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/setup/instance')->delete();
        MenuItem::where('link_url', '/setup/system')->update(['link_text' => 'Updates & Backups']);
    }
};
