<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAccountApproved;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\TrackSessionActivity;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * First-run, activation and restore
 *
 * Routes that must answer before an instance or warehouse is set up, or while its database is being replaced: the first-run
 * wizard (/install), a new warehouse's setup-code page (/activate), the three backup-restore entry points, invitations to join a
 * warehouse, the warehouse chooser's typed code, and the web app manifest.
 *
 * Access: mostly public on purpose. ResolveWarehouse and RedirectToSetup decide what is reachable in each state, and the controllers
 * refuse anything that is out of state. The restore status polls drop the session middleware (there is no sessions table while the
 * database is being replaced); keep that list when copying them.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// First-run setup wizard — only reachable until an administrator exists (see
// FirstRunService / RedirectToSetup); afterwards both just redirect to /login.
Route::get('/install', [SetupController::class, 'show'])->name('setup.show');
Route::post('/install', [SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');

// A new warehouse waiting for its setup code (MULTI_WAREHOUSE.md §7). ResolveWarehouse lets an awaiting-setup warehouse answer
// only these; the controller refuses anything once the warehouse is active.
Route::get('/activate', [WarehouseSetupController::class, 'show'])->name('activate.show');
Route::post('/activate/code', [WarehouseSetupController::class, 'code'])->middleware('throttle:30,1')->name('activate.code');
Route::post('/activate/setup', [WarehouseSetupController::class, 'setup'])->middleware('throttle:15,1')->name('activate.setup');

// The login page's "enter a warehouse code" (MULTI_WAREHOUSE.md §3): a typed code is a warehouse's id, rate-limited and answered plainly.
Route::post('/choose-warehouse', [WarehouseChooserController::class, 'choose'])->middleware('throttle:10,1')->name('warehouse.choose');

// Accepting an invitation to a warehouse with an existing login (MULTI_WAREHOUSE.md §6): the emailed link is public (the invitee
// can't be signed in to the inviting warehouse yet); the in-app list is for a login signed in anywhere it belongs.
Route::get('/invitations/{token}', [MembershipInvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [MembershipInvitationController::class, 'acceptLink'])->middleware('throttle:15,1')->name('invitations.accept');
Route::middleware('auth')->group(function () {
    Route::get('/account/invitations', [MembershipInvitationController::class, 'index'])->name('account.invitations');
    Route::post('/account/invitations/{id}/accept', [MembershipInvitationController::class, 'accept'])->middleware('throttle:15,1')->name('account.invitations.accept');
    Route::post('/account/invitations/{id}/decline', [MembershipInvitationController::class, 'decline'])->name('account.invitations.decline');
});

// Restoring a backup into a warehouse awaiting its setup code: the same controller as the first-run restore below, gated by the
// setup code instead of "never set up" (RestoreController::activating()).
Route::prefix('activate/restore')->name('activate.restore.')->group(function () {
    Route::post('/', [RestoreController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::get('/status', [RestoreController::class, 'status'])->name('status')
        ->withoutMiddleware([
            StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, VerifyCsrfToken::class,
            RedirectToSetup::class, HandleInertiaRequests::class, TrackSessionActivity::class, EnsureAccountApproved::class,
        ]);
    Route::post('/launch', [RestoreController::class, 'launch'])->middleware('throttle:20,1')->name('launch');
    Route::get('/{id}', [RestoreController::class, 'uploadStatus'])->name('upload.status');
    Route::put('/{id}', [RestoreController::class, 'uploadChunk'])->name('upload.chunk');
    Route::post('/{id}/complete', [RestoreController::class, 'complete'])->name('upload.complete');
    Route::delete('/{id}', [RestoreController::class, 'cancel'])->name('upload.cancel');
});

// A warehouse administrator restoring a backup into their own warehouse (RestoreController::owning()). Everything but the status
// needs a signed-in administrator; the status is open because the warehouse is `restoring` (ResolveWarehouse lets exactly
// /restore-status through) and the database, with its sessions, is being replaced.
Route::prefix('json/warehouse-restore')->name('warehouse.restore.')->middleware(['auth', 'permission:admin-system'])->group(function () {
    Route::post('/', [RestoreController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::post('/launch', [RestoreController::class, 'launch'])->middleware('throttle:5,1')->name('launch');
    Route::get('/{id}', [RestoreController::class, 'uploadStatus'])->name('upload.status');
    Route::put('/{id}', [RestoreController::class, 'uploadChunk'])->name('upload.chunk');
    Route::post('/{id}/complete', [RestoreController::class, 'complete'])->name('upload.complete');
    Route::delete('/{id}', [RestoreController::class, 'cancel'])->name('upload.cancel');
});
Route::get('/restore-status', [RestoreController::class, 'status'])->name('restore.status')
    ->withoutMiddleware([
        StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, VerifyCsrfToken::class,
        RedirectToSetup::class, HandleInertiaRequests::class, TrackSessionActivity::class, EnsureAccountApproved::class,
    ]);

// First-run "restore a backup" path. Named setup.* so RedirectToSetup lets it through; the controller
// itself refuses anything but a never-set-up instance. The status poll skips the session middleware: while
// the database is being replaced there is no sessions table to read (nor a setup_state table to ask).
Route::prefix('install/restore')->name('setup.restore.')->group(function () {
    Route::post('/', [RestoreController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::get('/status', [RestoreController::class, 'status'])->name('status')
        ->withoutMiddleware([
            StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, VerifyCsrfToken::class,
            RedirectToSetup::class, HandleInertiaRequests::class, TrackSessionActivity::class, EnsureAccountApproved::class,
        ]);
    Route::post('/launch', [RestoreController::class, 'launch'])->middleware('throttle:20,1')->name('launch');
    Route::get('/{id}', [RestoreController::class, 'uploadStatus'])->name('upload.status');
    Route::put('/{id}', [RestoreController::class, 'uploadChunk'])->name('upload.chunk');
    Route::post('/{id}/complete', [RestoreController::class, 'complete'])->name('upload.complete');
    Route::delete('/{id}', [RestoreController::class, 'cancel'])->name('upload.cancel');
});

// Web app manifest, for "Add to Home Screen" / install on phones and tablets. A route rather than a static
// file so the installed name follows this instance's APP_NAME. Fetched by the browser without cookies, so
// no session, and it must answer before first-run setup too.
Route::get('/manifest.webmanifest', function () {
    return response()->json([
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#202934',
        'theme_color' => '#ffffff',
        'icons' => [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
})->name('manifest')->withoutMiddleware([
    StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, VerifyCsrfToken::class,
    RedirectToSetup::class, HandleInertiaRequests::class, TrackSessionActivity::class, EnsureAccountApproved::class,
]);
