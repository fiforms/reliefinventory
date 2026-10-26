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
use App\Http\Middleware\VerifyFeedbackIngestSecret;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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
            'trust-auth' => \App\Http\Middleware\TrustAuth::class,
            'display-access' => EnsureDeviceAccess::class,
            'feedback-ingest-secret' => VerifyFeedbackIngestSecret::class,
        ]);

        // Instance-to-instance feedback ingest has no session/cookie to carry
        // a CSRF token — it's authenticated by shared secret instead (see
        // VerifyFeedbackIngestSecret).
        $middleware->validateCsrfTokens(except: [
            'json/feedback/ingest',
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
    })->create();
