<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskLocation;
use App\Services\PinLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Kiosk lock mode (2026-08-23 design pass) — see PinLoginService's
 * enableKioskMode()/clearKioskMode() and the trusted_devices migration doc
 * comment for the full mechanism. Enabling logs the operator out
 * immediately; the frontend then does a full page reload so the browser
 * re-requests the kiosk page as a guest and picks up the new state
 * cleanly, rather than trying to reconcile a half-authenticated Inertia
 * page in place.
 */
class KioskModeController extends Controller
{
    public function enable(Request $request, PinLoginService $pinLogin): JsonResponse
    {
        $activeLocations = KioskLocation::where('active', true)->get();

        $locationId = $request->integer('location_id') ?: null;
        if ($locationId && ! $activeLocations->contains('id', $locationId)) {
            return response()->json(['message' => 'That location is not available.'], 422);
        }
        if (! $locationId) {
            if ($activeLocations->count() === 1) {
                $locationId = $activeLocations->first()->id;
            } elseif ($activeLocations->count() > 1) {
                return response()->json(['message' => 'Choose which location this kiosk is at.'], 422);
            }
        }

        $device = $pinLogin->resolveDevice($request);

        if (! $pinLogin->enableKioskMode($device, $request->user()->id)) {
            return response()->json([
                'message' => $pinLogin->settings()->enabled
                    ? 'This device must be approved for PIN unlock before it can run as a kiosk.'
                    : 'PIN unlock must be enabled system-wide before a device can run as a kiosk.',
            ], 422);
        }

        $device->update(['kiosk_location_id' => $locationId]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Kiosk mode enabled.']);
    }
}
