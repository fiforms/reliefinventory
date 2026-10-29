<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\DeviceArtifactController;
use App\Http\Controllers\DeviceHeartbeatController;
use App\Http\Controllers\DevicePrintJobController;
use App\Http\Controllers\DeviceSessionController;
use App\Http\Controllers\DeviceUpdateCheckController;
use App\Http\Controllers\FederationBackupController;
use App\Http\Controllers\FederationController;
use App\Http\Controllers\FeedbackIngestController;
use App\Http\Controllers\InstanceBackupApiController;
use App\Http\Controllers\InstanceFederationController;
use App\Http\Controllers\InstanceUpdateController;
use Illuminate\Support\Facades\Route;

// The host-side Docker updater (docker/updater/ri-updater.py): polls for a requested check/update and reports back.
// Shared-secret guarded; 404 unless RI_UPDATE_SECRET is set. See App\Models\Control\InstanceUpdate.
Route::middleware(['throttle:60,1', 'update-secret'])->prefix('instance/update')->group(function () {
    Route::get('/', [InstanceUpdateController::class, 'poll']);
    Route::post('/status', [InstanceUpdateController::class, 'report']);
});

// Server half of rstation/platform/docs/DEVICE_CONTRACT.md. The device's
// product sets api_base = /api/devices (rstation/product/backend/__init__.py);
// the liveness probe is the framework's /up, outside this prefix, as the
// contract specifies.
Route::prefix('devices')->group(function () {
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

// Instance-level trust (App\Models\Control\InstanceTrust): a computer paired to the whole instance by an instance administrator, today only to
// make and download whole-instance backups (the RI Launcher). The same two bootstrap calls and the same signing as federation/v1 below, against
// the control schema instead of one warehouse's. Served at the instance's address like the updater endpoints above.
Route::prefix('instance/v1')->group(function () {
    Route::post('/pair', [InstanceFederationController::class, 'pair'])->middleware('throttle:10,1');
    Route::post('/session', [InstanceFederationController::class, 'session'])->middleware(['instance-trust:,signed', 'throttle:60,1']);
    Route::get('/grants', [InstanceFederationController::class, 'grants'])->middleware(['instance-trust', 'throttle:120,1']);
    Route::prefix('backups')->middleware('instance-trust:instance.backup')->group(function () {
        Route::get('/', [InstanceBackupApiController::class, 'index'])->middleware('throttle:fed-backup-read');
        Route::post('/', [InstanceBackupApiController::class, 'store'])->middleware('throttle:fed-backup-create');
        Route::get('/{id}', [InstanceBackupApiController::class, 'show'])->whereNumber('id')->middleware('throttle:fed-backup-read');
        Route::get('/{id}/download', [InstanceBackupApiController::class, 'download'])->whereNumber('id')->middleware('throttle:fed-backup-download');
    });
});

// Federation (App\Services\Federation): pairing is unsigned (the code is the credential, plus proof of
// key possession); everything else is a signed request or a session token earned by one.
Route::prefix('federation/v1')->group(function () {
    Route::post('/pair', [FederationController::class, 'pair'])->middleware('throttle:10,1');
    Route::post('/session', [FederationController::class, 'session'])->middleware(['trust-auth:,signed', 'throttle:60,1']);
    Route::post('/feedback', [FeedbackIngestController::class, 'storeFromTrust'])->middleware(['trust-auth:feedback.send', 'throttle:120,1']);
    Route::get('/grants', [FederationController::class, 'grants'])->middleware(['trust-auth', 'throttle:120,1']);
    // Backups for an operator tool such as the RI Launcher (operator.full_read): list, ask for a new one, watch it, download it.
    Route::prefix('backups')->middleware('trust-auth:operator.full_read')->group(function () {
        Route::get('/', [FederationBackupController::class, 'index'])->middleware('throttle:fed-backup-read');
        Route::post('/', [FederationBackupController::class, 'store'])->middleware('throttle:fed-backup-create');
        Route::get('/{id}', [FederationBackupController::class, 'show'])->whereNumber('id')->middleware('throttle:fed-backup-read');
        Route::get('/{id}/download', [FederationBackupController::class, 'download'])->whereNumber('id')->middleware('throttle:fed-backup-download');
    });
});
