<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Models\ManagedDevice;
use Closure;
use Illuminate\Http\Request;

/**
 * Bearer-token auth for kiosk devices (DEVICE_CONTRACT.md). 401 is reserved
 * for a genuinely unknown/revoked token — the device wipes itself on it —
 * so nothing transient may ever produce one here. Deliberately separate
 * from user auth/Sanctum: a person's token can never match a device row.
 */
class AuthenticateManagedDevice
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $device = $token ? ManagedDevice::where('token_hash', ManagedDevice::hashToken($token))->first() : null;

        if (! $device || $device->revoked_at) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('managed_device', $device);

        return $next($request);
    }
}
