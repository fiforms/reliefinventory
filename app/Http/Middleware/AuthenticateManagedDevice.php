<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Authentication for paired devices (R Station, print client). A device is a client-kind trust (see
 * App\Services\Federation): it signs its requests with its own key, or sends the session token a signed call
 * earned, and TrustAuth does the checking. `device-auth:print.share` also requires that inbound grant.
 * 401 is reserved for a genuinely unknown/revoked/badly-signed device — the device wipes itself on it — so
 * nothing transient may ever produce one here (a clock problem is TrustAuth's 400).
 *
 * Deliberately separate from user auth/Sanctum: a person's token can never match a device.
 */
class AuthenticateManagedDevice
{
    public function __construct(private TrustAuth $trustAuth) {}

    public function handle(Request $request, Closure $next, string ...$options)
    {
        return $this->trustAuth->handle($request, function (Request $request) use ($next) {
            if (! $request->attributes->get('managed_device')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return $next($request);
        }, ...$options);
    }
}
