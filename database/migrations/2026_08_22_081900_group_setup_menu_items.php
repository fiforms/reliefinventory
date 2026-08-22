<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const WAREHOUSE_ITEMS = [
        '/setup/items',
        '/setup/categories',
        '/setup/locations',
    ];

    private const SYSTEM_ITEMS = [
        '/setup/users',
        '/setup/system',
        '/setup/pin-login',
        '/setup/feedback',
        '/setup/active-sessions',
        '/setup/import',
    ];

    public function up(): void
    {
        MenuItem::whereIn('link_url', self::WAREHOUSE_ITEMS)->update([
            'group_label' => 'Warehouse Administration',
        ]);

        MenuItem::whereIn('link_url', self::SYSTEM_ITEMS)->update([
            'group_label' => 'System Administration',
        ]);
    }

    public function down(): void
    {
        MenuItem::whereIn('link_url', array_merge(self::WAREHOUSE_ITEMS, self::SYSTEM_ITEMS))
            ->update(['group_label' => null]);
    }
};
