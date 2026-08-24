<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskSetting;
use Illuminate\Http\Request;

/**
 * The volunteer kiosk's front-screen welcome message — gated on
 * admin-system, matching every other system-wide toggle (backup schedule,
 * PIN login). Reading it for display on the kiosk itself happens through
 * the /volunteers/kiosk page route (an Inertia prop), not this
 * controller — that route is reachable with nobody logged in (kiosk-access
 * middleware), so it can't sit behind admin-system.
 */
class KioskSettingController extends Controller
{
    public function show()
    {
        return response()->json(['settings' => KioskSetting::current()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'welcome_message' => 'nullable|string|max:255',
        ]);

        $settings = KioskSetting::current();
        $settings->update($data);

        return response()->json(['settings' => $settings->fresh()]);
    }
}
