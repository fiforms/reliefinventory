<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\DeviceArtifactController;
use App\Http\Controllers\DeviceHeartbeatController;
use App\Http\Controllers\DevicePrintJobController;
use App\Http\Controllers\DeviceSessionController;
use App\Http\Controllers\DeviceUpdateCheckController;
use App\Http\Controllers\FederationController;
use App\Http\Controllers\FeedbackIngestController;
use Illuminate\Support\Facades\Route;

// Server half of rstation/platform/docs/DEVICE_CONTRACT.md. The device's
// product sets api_base = /api/devices (rstation/product/backend/__init__.py);
// the liveness probe is the framework's /up, outside this prefix, as the
// contract specifies.
Route::prefix('devices')->group(function () {
    // Pairing is by key now (POST /api/federation/v1/pair); this answers old clients with a reason instead of a 404.
    Route::post('/pair', fn () => response()->json(['message' => 'Pairing has moved: update this device\'s software, then pair it with a code from the Trust Console.'], 410));
    Route::post('/heartbeat', [DeviceHeartbeatController::class, 'heartbeat'])->middleware('device-auth');
    // Browser handoff for kiosk pages with nobody logged in (DeviceSessionController).
    Route::post('/session-ticket', [DeviceSessionController::class, 'ticket'])->middleware(['device-auth', 'throttle:30,1']);
    // Print-server endpoints (rstation/PRINT_SERVER.md). Throttled because a
    // polling client otherwise has no limit; several servers behind one NAT
    // share the per-IP bucket, hence the generous ceiling.
    Route::middleware(['device-auth:print.share', 'throttle:240,1'])->prefix('print-jobs')->group(function () {
        Route::get('/', [DevicePrintJobController::class, 'index']);
        Route::post('/{id}/claim', [DevicePrintJobController::class, 'claim'])->whereNumber('id');
        Route::get('/{id}/payload', [DevicePrintJobController::class, 'payload'])->whereNumber('id');
        Route::post('/{id}/result', [DevicePrintJobController::class, 'result'])->whereNumber('id');
    });
    // Another instance's devices asking this one's catalogue (see DeviceUpdateCheckController).
    Route::post('/update-check', [DeviceUpdateCheckController::class, 'check'])->middleware('throttle:60,1');
    // Unauthenticated by contract (devices fetch with plain GETs); the
    // per-release random token in the path is the access control.
    Route::get('/artifacts/{token}/{filename}', [DeviceArtifactController::class, 'download']);
});

// Federation (App\Services\Federation): pairing is unsigned (the code is the credential, plus proof of
// key possession); everything else is a signed request or a session token earned by one.
Route::prefix('federation/v1')->group(function () {
    Route::post('/pair', [FederationController::class, 'pair'])->middleware('throttle:10,1');
    Route::post('/session', [FederationController::class, 'session'])->middleware(['trust-auth:,signed', 'throttle:60,1']);
    Route::post('/feedback', [FeedbackIngestController::class, 'storeFromTrust'])->middleware(['trust-auth:feedback.send', 'throttle:120,1']);
    Route::get('/grants', [FederationController::class, 'grants'])->middleware(['trust-auth', 'throttle:120,1']);
});
