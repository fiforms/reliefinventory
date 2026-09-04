<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Closes the gap RegisteredUserController's flow relied on but never
 * actually enforced: registration logs the user in immediately (standard
 * Laravel Breeze pattern, so the browser session is authenticated before
 * email verification, let alone admin approval), and VerifyEmailController
 * self-serve-clears disabled_at the moment the email link is clicked —
 * disabled_at only ever gated *starting a new* login (see HasLoginGate),
 * so nothing stopped that already-authenticated session (or a second tab
 * bouncing off /login's guest middleware into an existing session) from
 * loading /dashboard and the real app shell with zero permissions granted
 * but the full menu chrome around it. Found via a live test registration
 * (Josh Green, demo instance, 2026-09-03).
 *
 * Registered globally in bootstrap/app.php (not a per-route alias) so a
 * newly added route is closed by default instead of relying on every
 * future route remembering to add this check — the opposite failure mode
 * from what let this happen. Blocks nothing for a guest (no session yet)
 * or an approved account (every pre-existing account + every admin-created
 * one — see the approved_at migration and UserAdminController::store).
 */
class EnsureAccountApproved
{
    /**
     * Routes a pending, self-registered account may still reach: the
     * verification flow itself, the "what brings you here" / pending
     * screens it lands on afterward, and logout. Nothing else — in
     * particular no dashboard, no /json/* data endpoint.
     */
    private const ALLOWED_ROUTES = [
        'verification.notice',
        'verification.verify',
        'verification.send',
        'registration.track',
        'registration.track.store',
        'registration.pending',
        'logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (! $user || ! $user->isPendingApproval()) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('json/*')) {
            abort(403, 'Your account is still pending approval.');
        }

        return redirect()->route('registration.pending');
    }
}
