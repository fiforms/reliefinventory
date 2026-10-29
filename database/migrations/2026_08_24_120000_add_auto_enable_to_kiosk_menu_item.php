<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Tapping the "Volunteer Kiosk" Setup tile now goes straight into the
 * enable-kiosk-mode confirmation instead of landing on the kiosk page and
 * requiring a second tap on "Enable Kiosk Mode" — see VolunteerKiosk.vue's
 * `autoEnable` prop (since removed) and the confirm modal it opens on load.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '/volunteers/kiosk')->update([
            'link_url' => '/volunteers/kiosk?enable=1',
        ]);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/volunteers/kiosk?enable=1')->update([
            'link_url' => '/volunteers/kiosk',
        ]);
    }
};
