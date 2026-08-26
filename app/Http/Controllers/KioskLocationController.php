<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskLocation;
use Illuminate\Http\Request;

/**
 * Index/store/update for kiosk_locations, gated admin-system (Kiosk
 * Settings page). No destroy — locations may be referenced by devices and
 * sign_in_categories; deactivate via `active` instead. `index` is also
 * reachable from KioskEnableConfirmModal (still admin-system, since only a
 * logged-in operator enabling kiosk mode needs the list) to offer a
 * location picker when more than one active location exists.
 */
class KioskLocationController extends Controller
{
    private const VALIDATION_RULES = [
        'name' => 'required|string|max:255',
        'welcome_message' => 'nullable|string|max:255',
        'active' => 'boolean',
    ];

    public function index()
    {
        return response()->json([
            'records' => KioskLocation::orderBy('name')->get(),
        ]);
    }

    /**
     * Active locations only, gated operate-volunteer-kiosk rather than
     * admin-system — this is what KioskEnableConfirmModal calls to offer a
     * location picker to whoever's enabling kiosk mode, not just a
     * settings-page administrator.
     */
    public function active()
    {
        return response()->json([
            'records' => KioskLocation::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $location = KioskLocation::create($data);

        return response()->json([
            'message' => 'Location created successfully.',
            'record' => $location,
        ], 201);
    }

    public function update(Request $request, KioskLocation $kioskLocation)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $kioskLocation->update($data);

        return response()->json([
            'message' => 'Location updated successfully.',
            'record' => $kioskLocation->fresh(),
        ]);
    }
}
