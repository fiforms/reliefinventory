<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class ApiAuthenticate extends BaseAuthenticate
{
    /**
     * Handle unauthenticated users.
     *
     * @param  Request  $request
     *
     * @throws HttpResponseException
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Unauthorized',
        ], 401));
    }
}
