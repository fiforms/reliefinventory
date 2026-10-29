<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * R Station devices, printers and firmware
 *
 * The device fleet admin page and its JSON (pairing, printers, downloads) and firmware releases. The device-facing endpoints that the
 * devices themselves call are in routes/api.php.
 *
 * Access: `manage-devices`; firmware is the narrower `manage-firmware`.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// R Station device fleet (DEVICE_CONTRACT.md): the admin page and
// its JSON. The device-facing endpoints are in routes/api.php.
Route::get('/setup/devices', function () {
    return Inertia::render('RStationDevices', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/devices'),
        'canManageFirmware' => (bool) Auth::user()->hasPermission('manage-firmware'),
    ]);
})->middleware(['auth', 'permission:manage-devices']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-devices']], function () {
    Route::get('/managed-devices', [ManagedDeviceController::class, 'index']);
    Route::put('/managed-devices/{managedDevice}', [ManagedDeviceController::class, 'update']);
    Route::post('/managed-devices/{managedDevice}/revoke', [ManagedDeviceController::class, 'revoke']);
    Route::delete('/managed-devices/{managedDevice}', [ManagedDeviceController::class, 'destroy']);
    Route::post('/device-pairing-codes', [ManagedDeviceController::class, 'createPairingCode']);

    // Images and the print client: for anyone who can pair a device (uploaded under manage-firmware).
    Route::get('/device-downloads', [DeviceDownloadController::class, 'index']);
    Route::get('/device-downloads/{deviceRelease}/{filename}', [DeviceDownloadController::class, 'download']);

    // Typing the print queues a print server reports (see PrinterAdminController).
    Route::get('/printer-overview', [PrinterAdminController::class, 'overview']);
    Route::post('/printers/reorder', [PrinterAdminController::class, 'reorder']);
    Route::put('/printers/label-settings', [PrinterAdminController::class, 'saveLabelSettings']);
    Route::put('/printers/{printer}', [PrinterAdminController::class, 'update']);
    Route::delete('/printers/{printer}', [PrinterAdminController::class, 'destroy']);
});

// All printers at a glance: drag-to-order and live status. Clearing a queue from here is the
// existing manage-print-queue action (POST /json/print-jobs/clear).
Route::get('/setup/printers', function () {
    return Inertia::render('PrinterManagement', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/printers'),
        'canClearQueue' => (bool) Auth::user()->hasPermission('manage-print-queue'),
        // Uploading a PDF to print is the same permission as clearing/diverting the queue.
        'canUploadPdf' => (bool) Auth::user()->hasPermission('manage-print-queue'),
    ]);
})->middleware(['auth', 'permission:manage-devices']);

// Firmware (the app/OS release catalogue and where devices look for it) is its own permission,
// narrower than manage-devices: only fleet admins should be able to publish a release.
Route::get('/setup/firmware', function () {
    return Inertia::render('RStationFirmware',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/firmware')]);
})->middleware(['auth', 'permission:manage-firmware']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-firmware']], function () {
    Route::get('/firmware-source', [DeviceReleaseController::class, 'source']);
    Route::put('/firmware-source', [DeviceReleaseController::class, 'updateSource']);
    Route::get('/device-releases', [DeviceReleaseController::class, 'index']);
    Route::post('/device-releases', [DeviceReleaseController::class, 'store']);
    // Chunked upload for large files (see DeviceReleaseController).
    Route::post('/device-releases/uploads', [DeviceReleaseController::class, 'startUpload']);
    Route::get('/device-releases/uploads/{id}', [DeviceReleaseController::class, 'uploadStatus']);
    Route::put('/device-releases/uploads/{id}', [DeviceReleaseController::class, 'uploadChunk']);
    Route::post('/device-releases/uploads/{id}/complete', [DeviceReleaseController::class, 'finishUpload']);
    Route::delete('/device-releases/uploads/{id}', [DeviceReleaseController::class, 'cancelUpload']);
    Route::post('/device-releases/{deviceRelease}/tag', [DeviceReleaseController::class, 'tag']);
    Route::post('/device-releases/{deviceRelease}/untag', [DeviceReleaseController::class, 'untag']);
    Route::delete('/device-releases/{deviceRelease}', [DeviceReleaseController::class, 'destroy']);
});
