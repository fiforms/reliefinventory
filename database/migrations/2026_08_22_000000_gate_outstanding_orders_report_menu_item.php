<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * '/reports/orders' now renders a real report instead of the ComingSoon
     * placeholder — gate it on 'view-reports', same as '/reports/inventory'
     * (see 2026_08_14_160000_gate_inventory_report_menu_item.php).
     */
    public function up(): void
    {
        MenuItem::where('link_url', '/reports/orders')->update(['permission_key' => 'view-reports']);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/reports/orders')->update(['permission_key' => 'general-access']);
    }
};
