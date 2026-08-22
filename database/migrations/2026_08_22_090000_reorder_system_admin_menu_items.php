<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

// Re-sequences the System Administration group into three logical clusters:
// user/access management, then communication tools, then data/system
// maintenance — rather than the incidental order items were added in.
return new class extends Migration
{
    private const NEW_ORDER = [
        '/setup/users' => 50,          // User Administration
        '/setup/active-sessions' => 55, // User Activity
        '/setup/pin-login' => 60,       // PIN Login
        '/setup/banner' => 65,          // Site Banner
        '/setup/feedback' => 70,        // Feedback & Bug Reports
        '/setup/import' => 75,          // Data Import
        '/setup/system' => 80,          // Updates & Backups
    ];

    private const OLD_ORDER = [
        '/setup/users' => 50,
        '/setup/system' => 60,
        '/setup/pin-login' => 65,
        '/setup/banner' => 68,
        '/setup/feedback' => 70,
        '/setup/active-sessions' => 71,
        '/setup/import' => 72,
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
