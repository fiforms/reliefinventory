<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Services\Warehouses\WarehouseContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tab guard (MULTI_WAREHOUSE.md §3). Warehouses on one host share the browser's cookies, so a tab still showing
 * warehouse A after another tab switched the browser to B would otherwise act on B: CSRF doesn't catch it (the
 * XSRF-TOKEN cookie is browser-wide), and "delete record 42" would delete B's record 42.
 *
 * Every page knows its warehouse (the shared `warehouse` prop) and names it on each request: `X-RI-Warehouse` from
 * axios (which Inertia uses too), `_w` on a form post or a download link. A request naming another warehouse is
 * refused (409). While there is more than one warehouse it fails closed: a request that changes something, or an
 * Inertia visit, that names no warehouse is refused too. The session also remembers its warehouse.
 */
class GuardWarehouseTab
{
    public function __construct(private WarehouseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $current = $this->context->current();
        $named = $request->header('X-RI-Warehouse') ?? $request->input('_w');
        $named = is_string($named) ? strtoupper(trim($named)) : null;

        $mismatch = $named !== null && $named !== '' && $named !== $current->id;
        $unnamed = ($named === null || $named === '') && $this->context->multiple()
            && (! $request->isMethodSafe() || $request->header('X-Inertia'));
        if ($mismatch || $unnamed) {
            return $this->refuse($request);
        }

        // A session can only be found in its own warehouse's database, so this is belt and braces: never carry one
        // session into another warehouse (a restored copy of another warehouse's sessions table, say).
        if ($request->hasSession()) {
            $session = $request->session();
            $owner = $session->get('warehouse_id');
            if ($owner !== null && $owner !== $current->id) {
                $session->invalidate();
            }
            $session->put('warehouse_id', $current->id);
        }

        return $next($request);
    }

    private function refuse(Request $request): Response
    {
        $message = 'This page belongs to a different warehouse than the one this browser is now using, probably because '
            .'another tab switched warehouses. Nothing was changed. Reload the page to continue.';

        if ($request->header('X-Inertia')) {
            return Inertia::location(url('/warehouse-switched'));
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'warehouse_switched' => true], 409);
        }

        return redirect('/warehouse-switched');
    }
}
