<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details


namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

class ApiAuthenticate extends BaseAuthenticate
{
    /**
     * Handle unauthenticated users.
     *
     * @param \Illuminate\Http\Request $request
     * @param array $guards
     * @throws HttpResponseException
     */
    protected function unauthenticated($request, array $guards)
    {
            throw new HttpResponseException(response()->json([
                'message' => 'Unauthorized',
            ], 401));
    }
}

