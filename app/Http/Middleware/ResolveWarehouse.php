<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Services\Warehouses\WarehouseContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the warehouse a request works in (MULTI_WAREHOUSE.md §3/§4), before anything touches the database:
 *
 *  1. a `/w/<id>` path prefix — for machine surfaces (`/api/*`, `/up`, the well-known document) it is stripped
 *     for routing only (TrustAuth still checks signatures against the URI as sent, `ri.original_uri`); on any
 *     other GET it is a landing link, like `?w=`;
 *  2. a mapped hostname — locked: `?w=` and the selector cookie are ignored there, and a prefix naming another
 *     warehouse is refused;
 *  3. `?w=<id>` on a GET — sets the selector cookie and redirects to the same URL without it;
 *  4. the selector cookie;
 *  5. the default warehouse. Unprefixed machine URLs stay there permanently (everything paired before there
 *     were several warehouses uses one); browsers land there until the chooser exists (phase 3).
 *
 * An id that doesn't exist, or a warehouse that isn't active, is treated like no choice at all by `?w=` and
 * the cookie, so neither tells a visitor whether a warehouse exists.
 */
class ResolveWarehouse
{
    /** Machine surfaces a `/w/<id>` prefix is stripped from (§4). */
    private const MACHINE_PATHS = ['#^/api/#', '#^/up$#', '#^/\.well-known/ri-instance\.json$#'];

    public function __construct(private WarehouseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A previous request's prefixed root must not survive in a long-lived process (FrankenPHP worker mode).
        // Its warehouse is replaced by switchTo() below; a refused request returns before running anything.
        URL::forceRootUrl(null);

        $host = strtolower($request->getHost());
        $mapped = WarehouseDomain::where('hostname', $host)->first()?->warehouse;

        if (preg_match('#^/w/([A-Za-z0-9]{8})(/.*)?$#', $request->getPathInfo(), $m)) {
            return $this->prefixed($request, $next, strtoupper($m[1]), $m[2] ?? '/', $mapped);
        }

        if ($mapped) {
            if ($request->query->has('w') && $request->isMethodSafe() && ! self::isMachinePath($request->getPathInfo())) {
                return $this->redirectWithoutSelector($request);
            }

            return $this->run($mapped, $request, $next);
        }

        if ($request->query->has('w') && $request->isMethodSafe() && ! self::isMachinePath($request->getPathInfo())) {
            $chosen = Warehouse::findActive((string) $request->query('w'));

            return $this->redirectWithoutSelector($request, $chosen);
        }

        $cookie = $request->cookies->get(WarehouseContext::SELECTOR_COOKIE);
        $warehouse = (is_string($cookie) ? Warehouse::findActive($cookie) : null) ?? $this->context->defaultWarehouse();

        return $this->run($warehouse, $request, $next);
    }

    /** A prefixed request's URL root must not outlive it (a long-lived process; the test client builds URLs with it). */
    public function terminate(Request $request, Response $response): void
    {
        URL::forceRootUrl(null);
    }

    private function prefixed(Request $request, Closure $next, string $id, string $rest, ?Warehouse $mapped): Response
    {
        $warehouse = Warehouse::findActive($id);
        if (! $warehouse || ($mapped && ! $mapped->is($warehouse))) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! self::isMachinePath($rest)) {
            // A browser following a warehouse's address (an R Station opening its server URL): choose it and
            // continue without the prefix, like `?w=`.
            if (! $request->isMethodSafe()) {
                return response()->json(['message' => 'Not found.'], 404);
            }
            $query = $request->server->get('QUERY_STRING');

            return $this->select(redirect($rest.($query ? "?{$query}" : '')), $request, $mapped ? null : $warehouse);
        }

        // Route the request as if it had come without the prefix; the URI as sent stays available for signature
        // checks, and the URLs the response hands back (download links) keep the prefix.
        $original = $request->getRequestUri();
        $query = $request->server->get('QUERY_STRING');
        $server = $request->server->all();
        $server['REQUEST_URI'] = $rest.($query ? "?{$query}" : '');
        $request->initialize(
            $request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $server, $request->getContent(),
        );
        $request->attributes->set('ri.original_uri', $original);
        $request->attributes->set('ri.warehouse_prefix', "/w/{$warehouse->id}");

        return $this->run($warehouse, $request, $next, fn () => URL::forceRootUrl($request->getSchemeAndHttpHost()."/w/{$warehouse->id}"));
    }

    private static function isMachinePath(string $path): bool
    {
        return collect(self::MACHINE_PATHS)->contains(fn ($pattern) => preg_match($pattern, $path));
    }

    private function run(Warehouse $warehouse, Request $request, Closure $next, ?Closure $then = null): Response
    {
        if (! $warehouse->isActive()) {
            return $this->unavailable($request);
        }

        // Not undone on terminate: terminable middleware still need the request's own warehouse.
        $this->context->switchTo($warehouse);
        $then && $then();

        return $next($request);
    }

    /**
     * Redirect to the URL without `?w=`, choosing $warehouse (when there is one) on the way. The rest of the raw query
     * string is kept byte for byte: a signed link (email verification) must still match its signature.
     */
    private function redirectWithoutSelector(Request $request, ?Warehouse $warehouse = null): Response
    {
        $rest = collect(explode('&', (string) $request->server->get('QUERY_STRING')))
            ->reject(fn ($part) => $part === '' || strtolower(urldecode(strtok($part, '='))) === 'w')
            ->implode('&');

        return $this->select(redirect($request->getBaseUrl().$request->getPathInfo().($rest !== '' ? "?{$rest}" : '')), $request, $warehouse);
    }

    private function select(RedirectResponse $response, Request $request, ?Warehouse $warehouse): Response
    {
        if ($warehouse) {
            // Not encrypted (bootstrap/app.php): it only names a warehouse, which the visitor chose anyway, and it
            // is read before EncryptCookies runs.
            $response->headers->setCookie(Cookie::create(
                WarehouseContext::SELECTOR_COOKIE, $warehouse->id, now()->addYear(), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }

    private function unavailable(Request $request): Response
    {
        $message = 'This warehouse is not available right now.';

        return $request->expectsJson() || str_starts_with($request->getPathInfo(), '/api/')
            ? response()->json(['message' => $message], 503)
            : response($message, 503)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
