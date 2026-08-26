<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * The 2026-08-22 Customer->Partner rename (see
 * 2026_08_22_010000_rename_customer_role_to_partner.php) deliberately left
 * the /reports/customers URL itself alone, changing only its link text —
 * the route was still a "Coming Soon" placeholder, so nothing was at stake
 * yet. Finishing the codebase-wide rename (2026-08-23) is a good point to
 * also close that out: renames the route to /reports/partners so the path
 * matches the "Partner Report" label instead of reading as a leftover.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '/reports/customers')->update(['link_url' => '/reports/partners']);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/reports/partners')->update(['link_url' => '/reports/customers']);
    }
};
