<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * TODO.md item 5: "Create a user interface to add and manage default
 * ordering units" — the units table and its /json/units API already
 * existed (UnitController) with no page bound to it. Placed right after
 * "Edit Location Names" (order 33) in the same Warehouse Administration
 * group as items/categories/locations.
 *
 * Also adds Package Types (feedback #43) right after it — same shape of
 * gap: the packagetypes JSON API already existed with no page bound to it,
 * which was the underlying reason "package types" could block adding a
 * new item (nowhere to add one from).
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::create([
            'page_id' => 3,
            'link_text' => 'Default Ordering Units',
            'link_url' => '/setup/units',
            'graphic_url' => '/img/edit-statuses-icon.webp',
            'order' => 34,
            'group_label' => 'Warehouse Administration',
            'permission_key' => 'manage-units',
        ]);

        MenuItem::create([
            'page_id' => 3,
            'link_text' => 'Package Types',
            'link_url' => '/setup/package-types',
            'graphic_url' => '/img/edit-statuses-icon.webp',
            'order' => 35,
            'group_label' => 'Warehouse Administration',
            'permission_key' => 'manage-packagetypes',
        ]);
    }

    public function down(): void
    {
        MenuItem::whereIn('link_url', ['/setup/units', '/setup/package-types'])->delete();
    }
};
