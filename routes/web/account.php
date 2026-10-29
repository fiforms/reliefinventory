<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Services\GitVersionService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Any signed-in user
 *
 * What every logged-in user can reach without a specific permission: the dashboard, the profile, the About screen, the shared lookups
 * (menu, statuses, counties, states), notifications, and the `general-access` endpoints (submit feedback, dismiss the banner, geocode).
 *
 * Access: `auth` (and `permission:general-access` where shown). Anything that touches a resource belongs in that resource's file.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/qrcode-test', function () {
    return Inertia::render('QRCodeTest');
})->middleware(['auth', 'verified']);

// This installation's About screen (version, Docker, license): reached from the profile menu only (no MenuItem),
// open to any logged-in user. Distinct from the public /about project story above.
Route::get('/about-this-install', function () {
    $git = app(GitVersionService::class);

    return Inertia::render('AppAbout', [
        'version' => $git->release(),
        'commit' => $git->currentCommit(),
        'docker' => config('system.runtime') === 'docker',
    ]);
})->middleware(['auth']);

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Routes accessible  by everyone (including unknown users who just registered with an email address)
Route::group(['prefix' => 'json', 'middleware' => ['auth']], function () {
    Route::get('/menu-data', [MenuController::class, 'index']);

    // API route for listing all statuses
    Route::get('/statuses', [StatusController::class, 'index']);

    Route::get('/counties', [CountyController::class, 'index']);
    Route::get('/states', [CountyController::class, 'states']);

});

// The notification bell (AuthenticatedLayout.vue) — generic over Laravel's
// own Notifiable trait, currently only fed by KioskCheckInAlert. Just
// `auth`, no extra permission key: recipients are whoever the notification
// was addressed to, scoped naturally via $request->user()->notifications().
Route::group(['prefix' => 'json', 'middleware' => ['auth']], function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

// Any logged-in user can submit a report or dismiss the current banner —
// this isn't tied to any specific resource.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:general-access']], function () {
    // 10/minute — generous for a legitimate user submitting a real report,
    // enough to blunt automated probing/spam of the submission endpoint.
    Route::post('/feedback-reports', [FeedbackReportController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::post('/banner-dismiss', [BannerSettingController::class, 'dismiss']);
    // Shared by People and Order Entry (manage-people / manage-orders) — gated
    // loosely like the above since it only echoes back a geocoding result,
    // not a resource-specific read/write.
    Route::post('/geocode/county', [GeocodeController::class, 'lookupCounty']);
});
