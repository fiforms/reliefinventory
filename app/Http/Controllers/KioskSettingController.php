<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskSetting;
use Illuminate\Http\Request;

/**
 * The Sign-in Kiosk's behavior settings (currently just the idle-reset
 * timeout — per-location banner text lives on KioskLocation instead) —
 * gated on manage-kiosk (split from admin-system 2026-09-02), individually
 * delegable from every other system-wide toggle.
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
            'idle_reset_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        $settings = KioskSetting::current();
        $settings->update($data);

        return response()->json(['settings' => $settings->fresh()]);
    }
}
