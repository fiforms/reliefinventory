<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * "Customer" reads as a retail term for what this role actually is: a
 * POD/distribution-site account placing bulk requests against the
 * warehouse — the food-bank/disaster-relief industry term for that tier
 * is "partner agency" (Feeding America's model, and Adventist Community
 * Services' own language: warehouses paired with "distribution sites"
 * run by partner agencies/conferences). Raised by Tim on the 2026-08-20
 * call (see tim-call-2026-08-20-receiving-sorting-review memory),
 * resolved 2026-08-22. Renames the Role row + the two menu items that
 * still say "Customer" in their link text; permission keys
 * (manage-orders, etc.) are untouched, and the `orderdonations`/`Person`
 * schema itself never used the word "customer" as a column name, so no
 * schema change is needed beyond this row-level rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::where('name', 'Customer')->update([
            'name' => 'Partner',
            'description' => 'Organization or distribution-site contact receiving relief supplies',
        ]);

        MenuItem::where('link_url', '/reports/customers')->update(['link_text' => 'Partner Report']);
        MenuItem::where('link_url', '/setup/people')->update(['link_text' => 'Partner & Donor Info']);
    }

    public function down(): void
    {
        Role::where('name', 'Partner')->update([
            'name' => 'Customer',
            'description' => 'Person receiving relief supplies',
        ]);

        MenuItem::where('link_url', '/reports/customers')->update(['link_text' => 'Customer Report']);
        MenuItem::where('link_url', '/setup/people')->update(['link_text' => 'Customer & Donor Info']);
    }
};
