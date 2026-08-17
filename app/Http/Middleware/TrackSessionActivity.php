<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records the current request's path onto the sessions row (last_url), so
 * the admin "who's logged in" view (permission: admin-system) can show what
 * a person is doing, not just that they're online. Everything else that
 * view needs (user_id, ip_address, last_activity) Laravel already tracks
 * for the database session driver — this fills the one gap.
 *
 * A single UPDATE per request, keyed on the session's own primary key, so
 * it stays cheap even under normal traffic.
 */
class TrackSessionActivity
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && session()->getId()) {
            DB::table('sessions')
                ->where('id', session()->getId())
                ->update(['last_url' => $request->path()]);
        }

        return $next($request);
    }
}
