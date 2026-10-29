<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Models\ManagedDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `display-access:<permission>,<function>[,<function>...]` — gate for the chrome-free display pages
 * (and their JSON): EITHER a logged-in user holding <permission>, OR a request whose browser carries
 * the session cookie of a paired, non-revoked R Station whose configured Function is one of the
 * listed ones (see DeviceSessionController). Same OR shape as EnsureKioskAccess. A device session is
 * never a user: Auth::user() stays null and nothing downstream may treat it as one.
 */
class EnsureDeviceAccess
{
    public function handle(Request $request, Closure $next, string $permission, string ...$functions)
    {
        $user = Auth::user();
        if ($user && $user->hasPermission($permission)) {
            return $next($request);
        }

        $device = ManagedDevice::fromRequest($request);
        if ($device && in_array($device->kiosk_function, $functions, true)) {
            $request->attributes->set('managed_device', $device);

            return $next($request);
        }

        if ($request->expectsJson() || $request->is('json/*')) {
            abort(403, 'This device is not set up for this display.');
        }

        return redirect()->route('login');
    }
}
