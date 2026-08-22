<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

// Re-sequences the main menu to follow the actual goods lifecycle
// (Receiving -> Sorting -> Inventory -> Order Entry/Filling -> people ->
// reference/admin) instead of the incidental order items were added in —
// goods have to be received and sorted before they can be ordered/filled
// against, so those tiles now lead.
return new class extends Migration
{
    private const NEW_ORDER = [
        '/receiving' => 10,
        '/donation-sorting' => 20,
        '/inventory-movement' => 30,
        '/order-entry' => 40,
        '/order-filling' => 50,
        '/setup/people' => 60,
        '#reports' => 70,
        '#help' => 80,
        '#setup' => 90,
    ];

    private const OLD_ORDER = [
        '/order-entry' => 10,
        '/order-filling' => 20,
        '/receiving' => 25,
        '/donation-sorting' => 30,
        '/inventory-movement' => 40,
        '/setup/people' => 50,
        '#reports' => 80,
        '#help' => 85,
        '#setup' => 90,
    ];

    public function up(): void
    {
        foreach (self::NEW_ORDER as $url => $order) {
            MenuItem::where('link_url', $url)->update(['order' => $order]);
        }
    }

    public function down(): void
    {
        foreach (self::OLD_ORDER as $url => $order) {
            MenuItem::where('link_url', $url)->update(['order' => $order]);
        }
    }
};
