<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Item catalogue and lookups
 *
 * The master lists the rest of the app picks from: items, item types, categories, units, package types, uses and carton codes.
 *
 * Access: one permission per list (`manage-items`, `manage-itemtypes`, `manage-categories`, `manage-units`, `manage-packagetypes`,
 * `manage-uses`). Re-pointing an item at another ACS # is `admin-system`.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/setup/items', function () {
    return Inertia::render('ItemEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/items')]);
})->middleware(['auth', 'permission:manage-items,manage-itemtypes']);

Route::get('/setup/carton-codes', function () {
    return Inertia::render('CartonCodes',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/carton-codes')]);
})->middleware(['auth', 'permission:manage-items']);

Route::get('/setup/categories', function () {
    return Inertia::render('CategoryEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/categories')]);
})->middleware(['auth', 'permission:manage-categories']);

// Admin UI for the units table (/json/units already
// existed, gated the same way, with no page bound to it).
Route::get('/setup/units', function () {
    return Inertia::render('Units',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/units')]);
})->middleware(['auth', 'permission:manage-units']);

// Feedback #43: admin UI for the packagetypes table (/json/packagetypes
// already existed, gated the same way, with no page bound to it — see
// PackageTypeController's fixed validation rules for the underlying bug).
Route::get('/setup/package-types', function () {
    return Inertia::render('PackageTypes',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/package-types')]);
})->middleware(['auth', 'permission:manage-packagetypes']);

// Re-pointing an item at another ACS # rewrites how all of its history reads, so it is Administrator-only.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/items/{id}/move-impact', [ItemController::class, 'moveImpact'])->whereNumber('id');
    Route::post('/items/{id}/move', [ItemController::class, 'move'])->whereNumber('id');
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-items']], function () {
    Route::get('/items', [ItemController::class, 'index']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::put('/items/{id}', [ItemController::class, 'update']);
    Route::delete('/items/{id}', [ItemController::class, 'destroy']);
    Route::get('/carton-codes', [UpcController::class, 'indexCartons']);
    Route::delete('/carton-codes/{id}', [UpcController::class, 'destroyCarton'])->whereNumber('id');
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-units']], function () {
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);
    Route::put('/units/{id}', [UnitController::class, 'update']);
    Route::delete('/units/{id}', [UnitController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-categories']], function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-uses']], function () {
    Route::get('/uses', [UseController::class, 'index']);
    Route::post('/uses', [UseController::class, 'store']);
    Route::put('/uses/{id}', [UseController::class, 'update']);
    Route::delete('/uses/{id}', [UseController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-itemtypes']], function () {
    Route::get('/itemtypes', [ItemTypeController::class, 'index']);
    Route::get('/itemtypes/{mod}', [ItemTypeController::class, 'index']);
    Route::post('/itemtypes', [ItemTypeController::class, 'store']);
    Route::put('/itemtypes/{id}', [ItemTypeController::class, 'update']);
    Route::delete('/itemtypes/{id}', [ItemTypeController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-packagetypes']], function () {
    Route::get('/packagetypes', [PackageTypeController::class, 'index']);
    Route::post('/packagetypes', [PackageTypeController::class, 'store']);
    Route::put('/packagetypes/{id}', [PackageTypeController::class, 'update']);
    Route::delete('/packagetypes/{id}', [PackageTypeController::class, 'destroy']);
});
