<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Services\Warehouses\WarehouseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the warehouse a request works in (MULTI_WAREHOUSE.md §3/§4). For now that is always the default
 * warehouse; hostname, ?w, the selector cookie and machine URL prefixes come with multi-warehouse
 * resolution (phase 2).
 */
class ResolveWarehouse
{
    public function __construct(private WarehouseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Switching to another warehouse resets everything the previous one set, so in a long-lived process
        // (FrankenPHP worker mode) nothing carries over between warehouses; a failed resolution must forget()
        // (phase 2). Not undone on terminate: terminable middleware still need the request's own warehouse.
        $this->context->switchTo($this->context->defaultWarehouse());

        return $next($request);
    }
}
