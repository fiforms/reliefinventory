<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StatusController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// A sample route to verify the API is working
Route::get('/ping', function () {
    return response()->json(['message' => 'API is working!'], 200);
});

// API route for listing all statuses
Route::get('/statuses', [StatusController::class, 'index']);

