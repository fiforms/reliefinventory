<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Pallets, stock and locations
 *
 * Where things are: Inventory Movement, Pallet Management, locations, pallet status, pallet stock (contents, fill, adjust), warehouse
 * records, and the pallet and inventory labels. See dev/notes/STOCK_AND_LOCATIONS.md.
 *
 * Access: `manage-pallets`, `manage-locations`, `manage-warehouses`, and `adjust-inventory` for manual adjustments.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/inventory-movement', function () {
    return Inertia::render('PalletLocation', [
        'breadcrumb' => MenuItem::getBreadcrumb('/inventory-movement'),
        'canAdjust' => (bool) auth()->user()?->hasPermission('adjust-inventory'),
    ]);
})->middleware(['auth', 'permission:manage-pallets']);

Route::get('/pallet-management', function () {
    return Inertia::render('PalletManagement',
        ['breadcrumb' => MenuItem::getBreadcrumb('/pallet-management')]);
})->middleware(['auth', 'permission:manage-pallets']);

Route::get('/setup/locations/pick-path', function () {
    return Inertia::render('PickPath',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/locations/pick-path')]);
})->middleware(['auth', 'permission:manage-locations']);

Route::get('/setup/locations', function () {
    return Inertia::render('LocationEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/locations')]);
})->middleware(['auth', 'permission:manage-locations']);

Route::get('/report/pallet/{id}',
    [PalletReportController::class, 'generateReport'])
    ->name('report.pallet')
    ->middleware(['auth', 'permission:manage-pallets']);

// Inventory labels (one per item set put on a pallet), by pallet_stock_assignments id — the Inventory Movement screen's
// Fill Pallet, same gate as its endpoints.
Route::get('/report/inventory-labels',
    [InventoryLabelController::class, 'download'])
    ->name('report.inventory-labels')
    ->middleware(['auth', 'permission:manage-pallets']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-locations']], function () {
    Route::get('/locations', [LocationController::class, 'index']);
    Route::put('/locations/order', [LocationController::class, 'reorder']);
    Route::post('/locations', [LocationController::class, 'store']);
    Route::put('/locations/{id}', [LocationController::class, 'update']);
    Route::delete('/locations/{id}', [LocationController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-warehouses']], function () {
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::post('/warehouses', [WarehouseController::class, 'store']);
    Route::put('/warehouses/{id}', [WarehouseController::class, 'update']);
    Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-pallets']], function () {
    // Pallet Status (history/audit trail for pallets)
    Route::get('/palletstatus', [PalletStatusController::class, 'index']);
    Route::get('/palletstatus/summary', [PalletStatusController::class, 'summary']);
    Route::get('/palletstatus/location/{location}', [PalletStatusController::class, 'locationPallets']);
    Route::get('/palletstatus/lookup', [PalletStatusController::class, 'lookup']);
    Route::get('/palletstatus/statuses', [PalletStatusController::class, 'statuses']);
    Route::post('/palletstatus', [PalletStatusController::class, 'store']);
    Route::put('/palletstatus/{id}', [PalletStatusController::class, 'update']);
    Route::delete('/palletstatus/{id}', [PalletStatusController::class, 'destroy']);

    // Pallets (five-kind model: Receiving/Warehouse/Shipping/Hold/Quarantine)
    Route::get('/pallets/{id}/contents', [PalletStockController::class, 'contents'])->whereNumber('id');
    Route::post('/pallets/{id}/contents/adjust', [PalletStockController::class, 'adjust'])->whereNumber('id');
    Route::get('/pallet-stock/staging', [PalletStockController::class, 'staging']);
    Route::post('/pallet-stock/fill', [PalletStockController::class, 'fill']);
    Route::post('/pallet-stock/{id}/open', [PalletStockController::class, 'open'])->whereNumber('id');
    Route::post('/pallet-stock/{id}/seal', [PalletStockController::class, 'seal'])->whereNumber('id');
    Route::get('/pallets', [PalletController::class, 'index']);
    Route::get('/pallets/{kind}', [PalletController::class, 'index']);
    Route::get('/pallets/{kind}/{status}', [PalletController::class, 'index']);
    Route::post('/pallets', [PalletController::class, 'store']);
    Route::put('/pallets/{id}', [PalletController::class, 'update']);
    Route::delete('/pallets/{id}', [PalletController::class, 'destroy']);
});

// Manual inventory adjustments of a pallet's contents are their own permission, beyond ordinary stock movements.
Route::post('/json/pallet-stock/{id}/adjust-inventory', [PalletStockController::class, 'adjustInventory'])
    ->whereNumber('id')->middleware(['auth', 'permission:adjust-inventory']);
