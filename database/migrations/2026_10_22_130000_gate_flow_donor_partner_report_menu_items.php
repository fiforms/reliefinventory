<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const PATHS = ['/reports/flow', '/reports/donors', '/reports/partners'];

    /** The Flow, Donor and Partner reports are real pages now: gate them like the other reports, not the ComingSoon placeholder. */
    public function up(): void
    {
        MenuItem::whereIn('link_url', self::PATHS)->update(['permission_key' => 'view-reports']);
    }

    public function down(): void
    {
        MenuItem::whereIn('link_url', self::PATHS)->update(['permission_key' => 'general-access']);
    }
};
