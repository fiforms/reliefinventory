<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Route gate for instance administration: the signed-in login must be an instance admin (accounts.is_instance_admin),
 * which is separate from any warehouse permission. Usage: ->middleware('instance-admin').
 */
class EnsureInstanceAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Auth::user()?->account?->is_instance_admin, 403, 'Instance administrators only.');

        return $next($request);
    }
}
