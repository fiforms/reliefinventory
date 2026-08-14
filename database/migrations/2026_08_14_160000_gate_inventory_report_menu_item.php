<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * '/reports/inventory' now renders a real report instead of the
     * ComingSoon placeholder — gate it on the new 'view-reports' permission
     * instead of the placeholder-wide 'general-access' so it's controllable
     * independently of the other still-unbuilt report pages.
     */
    public function up(): void
    {
        MenuItem::where('link_url', '/reports/inventory')->update(['permission_key' => 'view-reports']);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/reports/inventory')->update(['permission_key' => 'general-access']);
    }
};
