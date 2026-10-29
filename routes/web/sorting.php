<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Donation sorting
 *
 * Scan-driven donation sorting (sessions and their lines) and the barcode/UPC lookups it uses. See
 * dev/notes/SCANNING_INTERFACE_NOTES.md and dev/notes/UPC_SCANNING.md.
 *
 * Access: `permission:manage-sorting`.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/donation-sorting', function () {
    return Inertia::render('DonationSorting',
        ['breadcrumb' => MenuItem::getBreadcrumb('/donation-sorting')]);
})->middleware(['auth', 'permission:manage-sorting']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-sorting']], function () {
    // Sorting sessions (scan-driven donation sorting; lines autosave one at a time)
    Route::get('/sorting-sessions', [SortingSessionController::class, 'index']);
    Route::post('/sorting-sessions', [SortingSessionController::class, 'store']);
    // Barcode scan -> item (our own items.upc first; an online suggestion only on a miss)
    Route::get('/upc/resolve', [UpcController::class, 'resolve']);
    Route::get('/upc/suggest', [UpcController::class, 'suggest']);
    Route::post('/upc/cartons', [UpcController::class, 'storeCarton']);
    Route::get('/sorting-sessions/pallet/{tag}', [SortingSessionController::class, 'pallet']);
    Route::post('/sorting-sessions/pallet/{tag}/empty', [SortingSessionController::class, 'palletEmpty']);
    Route::get('/sorting-sessions/{id}', [SortingSessionController::class, 'show']);
    Route::patch('/sorting-sessions/{id}', [SortingSessionController::class, 'update']);
    Route::post('/sorting-sessions/{id}/consolidate', [SortingSessionController::class, 'consolidate']);
    Route::post('/sorting-sessions/{id}/lines', [SortingSessionController::class, 'storeLine']);
    Route::put('/sorting-sessions/{id}/lines/{lineId}', [SortingSessionController::class, 'updateLine']);
    Route::delete('/sorting-sessions/{id}/lines/{lineId}', [SortingSessionController::class, 'destroyLine']);
});
