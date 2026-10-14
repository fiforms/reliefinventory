<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\DeviceArtifactController;
use App\Http\Controllers\DeviceHeartbeatController;
use App\Http\Controllers\DevicePairController;
use Illuminate\Support\Facades\Route;

// Server half of rstation/platform/docs/DEVICE_CONTRACT.md. The device's
// product sets api_base = /api/devices (rstation/product/backend/__init__.py);
// the liveness probe is the framework's /up, outside this prefix, as the
// contract specifies.
Route::prefix('devices')->group(function () {
    Route::post('/pair', [DevicePairController::class, 'pair'])->middleware('throttle:10,1');
    Route::post('/heartbeat', [DeviceHeartbeatController::class, 'heartbeat'])->middleware('device-auth');
    // Unauthenticated by contract (devices fetch with plain GETs); the
    // per-release random token in the path is the access control.
    Route::get('/artifacts/{token}/{filename}', [DeviceArtifactController::class, 'download']);
});
