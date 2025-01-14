<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\StatusController;
use App\Http\Controllers\MenuController;


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

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::group(['prefix' => 'json','middleware' => 'auth'], function()
{
    // API route for listing all statuses
    Route::get('/statuses', [StatusController::class, 'index']);
    Route::get('/menu-data', [MenuController::class, 'index']);

});


require __DIR__.'/auth.php';
