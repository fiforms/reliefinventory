<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Holds an account an administrator created with a temporary password
 * (people.password_change_required, set by UserAdminController::store) on
 * the change-password screen until the person picks their own. Registered
 * globally in bootstrap/app.php, like EnsureAccountApproved, so a new route
 * is closed by default rather than relying on it remembering this check.
 */
class EnsurePasswordChanged
{
    private const ALLOWED_ROUTES = [
        'password.change-required',
        'password.change-required.store',
        'logout',
        'switch-user',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (! $user || ! $user->password_change_required) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('json/*')) {
            abort(403, 'You need to choose a new password before continuing.');
        }

        return redirect()->route('password.change-required');
    }
}
