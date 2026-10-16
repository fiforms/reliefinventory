<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Middleware\AuthenticateManagedDevice;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAccountApproved;
use App\Http\Middleware\EnsureKioskAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackSessionActivity;
use App\Http\Middleware\VerifyFeedbackIngestSecret;
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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackSessionActivity::class,
            EnsureAccountApproved::class,
        ]);
        $middleware->alias([
            'permission' => CheckPermission::class,
            'kiosk-access' => EnsureKioskAccess::class,
            'device-auth' => AuthenticateManagedDevice::class,
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
