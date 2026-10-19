<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Services\FirstRunService;
use Closure;
use Illuminate\Http\Request;

/**
 * While the instance has never been set up (no Administrator, latch unset),
 * every web page redirects to the first-run wizard at /install — the same
 * "you've just installed it" experience WordPress gives. Anything that isn't
 * a plain page load (form posts, JSON) is refused instead of redirected, so
 * the open registration form can't be used to sneak an account in beside the
 * wizard. Costs one primary-key lookup per web request; once setup is done
 * it does nothing.
 */
class RedirectToSetup
{
    public function __construct(private FirstRunService $firstRun) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $this->firstRun->needsSetup() || $request->routeIs('setup.*')) {
            return $next($request);
        }

        if ($request->isMethodSafe() && ! $request->expectsJson() && ! $request->is('json/*')) {
            return redirect()->route('setup.show');
        }

        abort(403, 'This instance has not been set up yet. Open it in a browser to finish setup.');
    }
}
