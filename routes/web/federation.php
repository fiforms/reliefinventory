<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Federation
 *
 * This instance's public identity document and the trust console. The signed machine endpoints are in routes/api.php. See
 * dev/notes/FEDERATION.md.
 *
 * Access: the identity document is public; the console is `manage-trusts`.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Federation: the public half of this instance's identity (peers pin and domain-verify against it).
Route::get('/.well-known/ri-instance.json', [FederationController::class, 'identity'])->middleware('throttle:60,1');

Route::get('/setup/trust', function () {
    return Inertia::render('TrustConsole', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/trust'),
    ]);
})->middleware(['auth', 'permission:manage-trusts']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-trusts']], function () {
    Route::get('/trusts', [TrustConsoleController::class, 'index']);
    Route::post('/trusts/pairing-codes', [TrustConsoleController::class, 'createPairingCode']);
    Route::put('/trusts/identity', [TrustConsoleController::class, 'updateIdentity']);
    Route::post('/trusts/identity/regenerate', [TrustConsoleController::class, 'regenerateIdentity']);
    Route::post('/trusts/invitations', [TrustConsoleController::class, 'createInvitation']);
    Route::post('/trusts/accept-invitation', [TrustConsoleController::class, 'acceptInvitation']);
    Route::post('/trusts/{trust}/verify-domain', [TrustConsoleController::class, 'verifyDomain']);
    Route::post('/trusts/{trust}/refresh-grants', [TrustConsoleController::class, 'refreshGrants']);
    Route::put('/trusts/{trust}/grants', [TrustConsoleController::class, 'setGrants']);
    Route::post('/trusts/{trust}/revoke', [TrustConsoleController::class, 'revoke']);
});
