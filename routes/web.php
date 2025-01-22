<?php

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
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
});


require __DIR__.'/auth.php';
