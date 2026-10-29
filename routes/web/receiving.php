<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Receiving, donation offers, trucks and containers
 *
 * Receiving (donations, pallets, drivers, photos), donation offers, and the supporting trucks, containers and streams. See
 * dev/notes/RECEIVING.md.
 *
 * Access: `permission:manage-receiving`; the offer decisions are the narrower `manage-donation-offers`. Pallet labels for
 * Receiving are here; the other pallet labels are in web/inventory.php.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/receiving', function () {
    return Inertia::render('Receiving',
        ['breadcrumb' => MenuItem::getBreadcrumb('/receiving')]);
})->middleware(['auth', 'permission:manage-receiving']);

// Offers live inside Receiving, not as their own top-level nav item — no
// new MenuItem, just a nested URL reusing Receiving's breadcrumb entry.
// Page-level gate stays the loose manage-receiving (viewing/logging a call
// is fine for anyone who does intake); the decision endpoints below enforce
// the narrower manage-donation-offers.
Route::get('/receiving/offers', function () {
    return Inertia::render('DonationOffers', [
        'breadcrumb' => MenuItem::getBreadcrumb('/receiving'),
        // Whether to show the decision buttons at all — the endpoints they
        // call are still independently gated server-side either way, this
        // just avoids offering an action that's already known to 403 (see
        // dev/TODO.md, "Permissions and people").
        'canDecide' => Auth::user()->hasPermission('manage-donation-offers'),
    ]);
})->middleware(['auth', 'permission:manage-receiving']);

// Batch label print for a whole donation, used from the Receiving page —
// gated on manage-receiving to match the page that offers it.
Route::get('/report/pallets/donation/{id}',
    [PalletReportController::class, 'generateDonationLabels'])
    ->name('report.pallets.donation')
    ->middleware(['auth', 'permission:manage-receiving']);

// Pre-print Labels batch — an arbitrary set
// of just-created, not-yet-assigned pallet ids, same gate as the rest of
// Receiving's label printing.
Route::get('/report/pallets/preprint',
    [PalletReportController::class, 'generatePreprintLabels'])
    ->name('report.pallets.preprint')
    ->middleware(['auth', 'permission:manage-receiving']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-receiving']], function () {
    Route::get('/receiving', [ReceivingController::class, 'index']);
    Route::post('/receiving', [ReceivingController::class, 'store']);
    Route::put('/receiving/{id}', [ReceivingController::class, 'update']);
    Route::delete('/receiving/{id}', [ReceivingController::class, 'destroy']);
    Route::post('/receiving/{id}/pallets', [ReceivingController::class, 'createPallets']);
    // Pre-print Labels (replaces the deleted
    // /reports/labels page): print a batch of unassigned R labels ahead of
    // a donation record existing, then attach the ones actually used to a
    // real intake once it's entered — an alternate label-first path
    // alongside createPallets' existing enter-then-print flow.
    Route::post('/receiving/preprint-labels', [ReceivingController::class, 'preprintLabels']);
    Route::post('/receiving/{id}/attach-pallets', [ReceivingController::class, 'attachPreprintedPallets']);
    Route::post('/receiving/{id}/close-out', [ReceivingController::class, 'closeOut']);
    Route::post('/receiving/{id}/photo', [ReceivingController::class, 'uploadPhoto']);
    Route::get('/receiving/{id}/photo', [ReceivingController::class, 'photo']);

    // drivers: a sub-resource of intake (see DriverController), not its own
    // admin page/permission.
    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers', [DriverController::class, 'store']);
    Route::put('/drivers/{driver}', [DriverController::class, 'update']);

    // Recording/editing a donation offer (logging the call) is loose —
    // decision actions are gated separately below.
    Route::get('/donation-offers', [DonationOfferController::class, 'index']);
    Route::get('/donation-offers/unmatched-donations', [DonationOfferController::class, 'unmatchedDonations']);
    Route::post('/donation-offers', [DonationOfferController::class, 'store']);
    Route::put('/donation-offers/{donationOffer}', [DonationOfferController::class, 'update']);
    Route::post('/donation-offers/{donationOffer}/note', [DonationOfferController::class, 'addNote']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-donation-offers']], function () {
    Route::post('/donation-offers/{donationOffer}/approve', [DonationOfferController::class, 'approve']);
    Route::post('/donation-offers/{donationOffer}/refuse', [DonationOfferController::class, 'refuse']);
    Route::post('/donation-offers/{donationOffer}/divert', [DonationOfferController::class, 'divert']);
    Route::post('/donation-offers/{donationOffer}/cancel', [DonationOfferController::class, 'cancel']);
    Route::post('/donation-offers/{donationOffer}/match', [DonationOfferController::class, 'match']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-trucks']], function () {
    Route::get('/trucks', [TruckController::class, 'index']);
    Route::post('/trucks', [TruckController::class, 'store']);
    Route::put('/trucks/{id}', [TruckController::class, 'update']);
    Route::delete('/trucks/{id}', [TruckController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-containers']], function () {
    Route::get('/containers', [ContainerController::class, 'index']);
    Route::post('/containers', [ContainerController::class, 'store']);
    Route::put('/containers/{id}', [ContainerController::class, 'update']);
    Route::delete('/containers/{id}', [ContainerController::class, 'destroy']);
    Route::get('/container-types', [ContainerTypeController::class, 'index']);
    Route::post('/container-types', [ContainerTypeController::class, 'store']);
    Route::put('/container-types/{id}', [ContainerTypeController::class, 'update']);
    Route::delete('/container-types/{id}', [ContainerTypeController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-streams']], function () {
    Route::get('/streams', [StreamController::class, 'index']);
    Route::post('/streams', [StreamController::class, 'store']);
    Route::put('/streams/{id}', [StreamController::class, 'update']);
    Route::delete('/streams/{id}', [StreamController::class, 'destroy']);
});
