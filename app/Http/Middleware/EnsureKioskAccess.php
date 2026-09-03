<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Services\PinLoginService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Gate for the Sign-in Kiosk page and its JSON endpoints: EITHER a normal
 * logged-in operator with operate-kiosk (unchanged from before kiosk mode
 * existed, renamed 2026-09-02 from operate-volunteer-kiosk), OR a guest
 * request from a device that's currently in kiosk mode (see
 * KioskModeController/PinLoginService) — nothing in the sign-in flow
 * depends on Auth::id() (a kiosk sign-in is the volunteer's own record,
 * not staff data entry), so the guest path needs no special-casing
 * downstream. manage-kiosk also passes: the read routes here (GET
 * /kiosk-suggestions in particular) are shared by the Kiosk Settings admin
 * page, which is gated manage-kiosk and doesn't necessarily grant
 * operate-kiosk — someone configuring kiosk settings still needs to see
 * the same lists a kiosk device reads.
 */
class EnsureKioskAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if ($user && ($user->hasPermission('operate-kiosk') || $user->hasPermission('manage-kiosk'))) {
            return $next($request);
        }

        $device = app(PinLoginService::class)->deviceFromCookie($request);
        if ($device && $device->isInKioskMode()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('json/*')) {
            abort(403, 'This device is not set up as a kiosk.');
        }

        return redirect()->route('login');
    }
}
