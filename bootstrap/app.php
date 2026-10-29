<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Middleware\AuthenticateManagedDevice;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAccountApproved;
use App\Http\Middleware\EnsureDeviceAccess;
use App\Http\Middleware\EnsureKioskAccess;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\TrackSessionActivity;
use App\Http\Middleware\TrustAuth;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            RedirectToSetup::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackSessionActivity::class,
            EnsureAccountApproved::class,
            EnsurePasswordChanged::class,
        ]);
        // Must run before `auth`, or a not-yet-set-up instance would bounce
        // visitors to /login (or 401 a JSON call) instead of to the wizard.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RedirectToSetup::class);
        $middleware->alias([
            'permission' => CheckPermission::class,
            'kiosk-access' => EnsureKioskAccess::class,
            'device-auth' => AuthenticateManagedDevice::class,
            'trust-auth' => TrustAuth::class,
            'display-access' => EnsureDeviceAccess::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Someone re-opening an old email-verification link from Mail hours
        // later hits Laravel's raw "403 Invalid signature." page, which
        // looks broken even though it's just an expired signed URL — see
        // routes/auth.php's `verification.verify` route. Swap in a friendly
        // page pointing back to login instead of the framework default.
        $exceptions->render(function (InvalidSignatureException $e, $request) {
            if ($request->routeIs('verification.verify')) {
                return response()->view('errors.expired-verification-link', [], 403);
            }
        });

        // A page's /json call after its session timed out would otherwise
        // get a bare "Unauthenticated." 401 that pages show as an error.
        // Mark it so resources/js/bootstrap.js can send the browser to the
        // login page, and remember the page it was on so logging in returns
        // there. (Other 401s — a wrong PIN, the driver portal — carry no
        // marker and stay ordinary errors; /api is for devices, not pages.)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            if ($request->hasSession()) {
                $referer = $request->headers->get('referer');
                if ($referer && parse_url($referer, PHP_URL_HOST) === $request->getHost()) {
                    $path = parse_url($referer, PHP_URL_PATH) ?: '/';
                    $query = parse_url($referer, PHP_URL_QUERY);
                    redirect()->setIntendedUrl($path.($query ? '?'.$query : ''));
                }
                $request->session()->flash('status', 'Your session timed out. Please log in again.');
            }

            return response()
                ->json(['message' => 'Your session timed out. Please log in again.'], 401)
                ->header('X-RI-Login-Required', '1');
        });
    })->create();
