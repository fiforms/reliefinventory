<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\DeviceArtifactController;
use App\Http\Controllers\DeviceHeartbeatController;
use App\Http\Controllers\DevicePairController;
use App\Http\Controllers\DevicePrintJobController;
use Illuminate\Support\Facades\Route;

// Server half of rstation/platform/docs/DEVICE_CONTRACT.md. The device's
// product sets api_base = /api/devices (rstation/product/backend/__init__.py);
// the liveness probe is the framework's /up, outside this prefix, as the
// contract specifies.
Route::prefix('devices')->group(function () {
    Route::post('/pair', [DevicePairController::class, 'pair'])->middleware('throttle:10,1');
    Route::post('/heartbeat', [DeviceHeartbeatController::class, 'heartbeat'])->middleware('device-auth');
    // Print-server endpoints (rstation/PRINT_SERVER.md). Throttled because a
    // polling client otherwise has no limit; several servers behind one NAT
    // share the per-IP bucket, hence the generous ceiling.
    Route::middleware(['device-auth', 'throttle:240,1'])->prefix('print-jobs')->group(function () {
        Route::get('/', [DevicePrintJobController::class, 'index']);
        Route::post('/{id}/claim', [DevicePrintJobController::class, 'claim'])->whereNumber('id');
        Route::get('/{id}/payload', [DevicePrintJobController::class, 'payload'])->whereNumber('id');
        Route::post('/{id}/result', [DevicePrintJobController::class, 'result'])->whereNumber('id');
    });
    // Unauthenticated by contract (devices fetch with plain GETs); the
    // per-release random token in the path is the access control.
    Route::get('/artifacts/{token}/{filename}', [DeviceArtifactController::class, 'download']);
});
