<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints the host-side updater uses. The shared secret (`RI_UPDATE_SECRET`, set in compose.yaml) is the only
 * credential: the request's address is deliberately not consulted, because behind Docker's bridge the app sees the gateway
 * rather than 127.0.0.1, and a reverse proxy on the host would look local too. With no secret set the feature is off and the
 * endpoints do not exist (404).
 */
class InstanceUpdateSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('system.update_secret');
        if ($secret === '') {
            abort(404);
        }
        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($secret, $given)) {
            abort(401, 'Bad updater secret.');
        }

        return $next($request);
    }
}
