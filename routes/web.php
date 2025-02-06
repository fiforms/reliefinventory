<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;


use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;


Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
	'hostedBy' => env('HOSTED_BY','unknown'),
	'hostedLink' => env('HOSTED_LINK','https://example.com')
    ]);
});

    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->middleware(['auth', 'verified'])->name('dashboard');
    
    Route::get('/order-entry', function () {
        return Inertia::render('OrderEntry');
    })->middleware(['auth', 'verified'])->name('entry');
    
    Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::group(['prefix' => 'json','middleware' => 'auth'], function()
{
    // API route for listing all statuses
    Route::get('/statuses', [StatusController::class, 'index']);
    Route::get('/people', [PeopleController::class, 'index']);
    Route::get('/menu-data', [MenuController::class, 'index']);
    
    // Items
    Route::get('/items', [ItemController::class, 'index']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::put('/items/{id}', [ItemController::class, 'update']);
    Route::delete('/items/{id}', [ItemController::class, 'destroy']);
    
    // ItemTypes
    Route::get('/itemtypes', [ItemTypeController::class, 'index']);
    Route::post('/itemtypes', [ItemTypeController::class, 'store']);
    Route::put('/itemtypes/{id}', [ItemTypeController::class, 'update']);
    Route::delete('/itemtypes/{id}', [ItemTypeController::class, 'destroy']);
    
    // PackageTypes
    Route::get('/packagetypes', [PackageTypeController::class, 'index']);
    Route::post('/packagetypes', [PackageTypeController::class, 'store']);
    Route::put('/packagetypes/{id}', [PackageTypeController::class, 'update']);
    Route::delete('/packagetypes/{id}', [PackageTypeController::class, 'destroy']);
    
    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::put('/orders/{id}', [OrderController::class, 'update']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
    
});


require __DIR__.'/auth.php';
