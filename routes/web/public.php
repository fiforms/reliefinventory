<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\TurnstileService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Public pages (no login)
 *
 * Pages anyone can open without an account: the welcome and about pages, the driver portal (its own phone + PIN login), the R Station
 * browser handoff, and the chrome-free display screens for an always-on R Station (display-access).
 *
 * Access: no `auth` middleware by design. A route added here is reachable by the whole internet, so say why in a comment and
 * rate-limit anything that accepts input.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        // Hidden while Turnstile is on but keyless: the page would only show a setup notice.
        'canRegister' => Route::has('register') && ! app(TurnstileService::class)->misconfigured(),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
        'hostedBy' => config('app.hosted_by'),
        'hostedLink' => config('app.hosted_link'),
    ]);
});

// Linked from Welcome.vue's HOSTED_BY credit line — public, no auth, same
// as Welcome itself.
Route::get('/about', function () {
    return Inertia::render('About');
});

// Where the tab guard (GuardWarehouseTab) sends a tab left on a warehouse the browser no longer uses.
Route::get('/warehouse-switched', fn () => Inertia::render('WarehouseSwitched'))->name('warehouse.switched');

// Driver Portal — deliberately NOT auth-gated: a driver has no account (see
// Driver's doc comment) and signs in with just phone + PIN, handled inside
// DriverPortalController itself. A staff visitor with manage-orders sees an
// admin read-only view of the same data instead — see the controller's doc
// comment for the full dual-audience design.
Route::get('/driver-portal', [DriverPortalController::class, 'page']);
Route::post('/driver-portal/login', [DriverPortalController::class, 'login'])->middleware('throttle:6,1');
Route::post('/driver-portal/logout', [DriverPortalController::class, 'logout']);
Route::get('/driver-portal/loads', [DriverPortalController::class, 'loads']);
Route::post('/driver-portal/loads/{id}/bol', [DriverPortalController::class, 'uploadBol']);

// Browser handoff for a paired R Station (see DeviceSessionController): trades a single-use ticket for
// the device-session cookie, then lands on the device's Function page. Unauthenticated by design.
Route::get('/device-session/{ticket}', [DeviceSessionController::class, 'start'])->middleware('throttle:30,1');

// Chrome-free, auto-refreshing stats screens for an always-on R Station display, shown with nobody
// logged in (display-access = the matching permission OR a device session with that Function).
Route::get('/display/sitrep', function () {
    return Inertia::render('Display/Stats', ['mode' => 'sitrep']);
})->middleware('display-access:view-sitrep,status_sitrep');
Route::get('/display/dashboard', function () {
    return Inertia::render('Display/Stats', ['mode' => 'dashboard']);
})->middleware('display-access:view-dashboard,status_dashboard');
Route::group(['prefix' => 'json/display'], function () {
    Route::get('/sitrep', [SitrepController::class, 'index'])->middleware('display-access:view-sitrep,status_sitrep');
    Route::get('/dashboard', [DashboardController::class, 'display'])->middleware('display-access:view-dashboard,status_dashboard');
});
