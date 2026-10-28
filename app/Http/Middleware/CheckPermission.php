<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Route/action gate for the granular permissions model. Replaces CheckRole
 * (a numeric role_bitpack magnitude comparison — not a real bitmask test,
 * and vulnerable to a person's role bits summing past a threshold they
 * were never granted) outright, per granular-permissions-model.md.
 *
 * Usage: ->middleware('permission:manage-items') or, for a route requiring
 * more than one capability, ->middleware('permission:manage-items,manage-categories').
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$keys)
    {
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        foreach ($keys as $key) {
            if (! $user->hasPermission($key)) {
                abort(403, "Missing required permission: {$key}");
            }
        }

        return $next($request);
    }
}
