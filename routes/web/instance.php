<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Instance administration
 *
 * What belongs to the whole instance rather than one warehouse: the Instance Admin page, whole-instance backups, and warehouse
 * management. See dev/notes/MULTI_WAREHOUSE_NOTES.md.
 *
 * Access: `instance-admin` middleware (an instance administrator), never a warehouse permission.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Instance Admin: what belongs to the whole instance rather than one warehouse — software updates, offline mode,
// whole-instance backups (and later warehouse management). Instance administrators only; the JSON half is
// /json/instance/*, which reuses SystemController's actions behind the same gate.
Route::get('/setup/instance', function () {
    return Inertia::render('InstanceAdmin', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/instance'),
        'runtime' => config('system.runtime') === 'docker' ? 'docker' : 'systemd',
    ]);
})->middleware(['auth', 'instance-admin']);

Route::group(['prefix' => 'json/instance', 'middleware' => ['auth', 'instance-admin']], function () {
    Route::get('/status', [SystemController::class, 'status']);
    Route::post('/check-updates', [SystemController::class, 'checkUpdates']);
    Route::post('/update', [SystemController::class, 'update']);
    Route::put('/offline-mode', [SystemController::class, 'saveOfflineMode']);

    // Full Instance Backups: the schedule, the list, "back up now" (a request to the provisioner) and download. Writes re-ask for the password.
    Route::get('/backups', [InstanceBackupController::class, 'index']);
    Route::get('/backups/{name}/download', [InstanceBackupController::class, 'download'])->where('name', '[A-Za-z0-9._-]+');
    Route::middleware('throttle:20,1')->group(function () {
        Route::put('/backups/schedule', [InstanceBackupController::class, 'updateSchedule']);
        Route::post('/backups', [InstanceBackupController::class, 'store']);
        Route::post('/backups/recovery-key', [InstanceBackupController::class, 'recoveryKey']);
        Route::post('/backups/recovery-key/confirm', [InstanceBackupController::class, 'confirmRecoveryKey']);
        Route::post('/backups/clients/code', [InstanceBackupController::class, 'pairingCode']);
        Route::delete('/backups/clients/{id}', [InstanceBackupController::class, 'revokeClient'])->whereNumber('id');
    });

    // Warehouse management. Every write re-asks for the signed-in person's password (InstanceWarehouseController::guarded).
    Route::get('/warehouses', [InstanceWarehouseController::class, 'index']);
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/warehouses', [InstanceWarehouseController::class, 'store']);
        Route::put('/warehouses/{id}', [InstanceWarehouseController::class, 'update']);
        Route::post('/warehouses/{id}/suspend', [InstanceWarehouseController::class, 'suspend']);
        Route::post('/warehouses/{id}/activate', [InstanceWarehouseController::class, 'activate']);
        Route::post('/warehouses/{id}/resume', [InstanceWarehouseController::class, 'resume']);
        Route::post('/warehouses/{id}/archive', [InstanceWarehouseController::class, 'archive']);
        Route::post('/warehouses/{id}/setup-code', [InstanceWarehouseController::class, 'setupCode']);
        Route::post('/warehouses/{id}/domains', [InstanceWarehouseController::class, 'addDomain']);
        Route::delete('/warehouses/{id}/domains', [InstanceWarehouseController::class, 'removeDomain']);
        Route::delete('/warehouses/{id}', [InstanceWarehouseController::class, 'destroy']);
    });
});
