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
        // A no-op in FrankenPHP's classic mode, where every request is a fresh process. In a long-lived one
        // (worker mode) nothing may carry over from the previous request, even when resolution fails. Reset
        // here rather than on terminate: terminable middleware that run after the response still need the
        // request's own warehouse.
        $this->context->forget();
        $this->context->switchTo($this->context->defaultWarehouse());

        return $next($request);
    }
}
