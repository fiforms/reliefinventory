<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Training warehouse tools
 *
 * A training warehouse's class tools: trainee sign-up (/join, public, only open in a training warehouse), Prepare Training, products,
 * the class report and its PDFs. See dev/notes/TRAINING_WAREHOUSE.md.
 *
 * Access: `manage-training`, a key only a training warehouse has. The team sheets print for anyone signed in.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Trainee self sign-up (TraineeSignupController): public, open only in a training warehouse. A whole room signs
// up at once from one Wi-Fi network (one IP), so the limit (80/min) covers a room of ~55; Turnstile keeps bots out.
Route::get('/join', [TraineeSignupController::class, 'show'])->name('join');
Route::post('/join', [TraineeSignupController::class, 'store'])->middleware('throttle:80,1');

// A training warehouse's class tools (App\Services\TrainingMode): all manage-training, a key only a training warehouse has.
// The team activity sheets print from /help, for anyone signed in, like the help guides.
Route::get('/setup/training', function () {
    return Inertia::render('Setup/Training', ['breadcrumb' => MenuItem::getBreadcrumb('/setup/training')]);
})->middleware(['auth', 'permission:manage-training']);
Route::get('/setup/training/products', function () {
    return Inertia::render('Setup/TrainingProducts', ['breadcrumb' => MenuItem::getBreadcrumb('/setup/training')]);
})->middleware(['auth', 'permission:manage-training']);
Route::get('/reports/training', function () {
    return Inertia::render('TrainingReport', ['breadcrumb' => MenuItem::getBreadcrumb('/reports/training')]);
})->middleware(['auth', 'permission:manage-training']);
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-training']], function () {
    Route::get('/training/status', [TrainingAdminController::class, 'status']);
    Route::post('/training/prepare', [TrainingAdminController::class, 'prepare']);
    Route::put('/training/event', [TrainingAdminController::class, 'setEvent']);
    Route::get('/training/signatures/{slot}', [TrainingAdminController::class, 'signature'])->whereNumber('slot');
    Route::post('/training/signatures/{slot}', [TrainingAdminController::class, 'uploadSignature'])->whereNumber('slot');
    Route::delete('/training/signatures/{slot}', [TrainingAdminController::class, 'deleteSignature'])->whereNumber('slot');
    Route::post('/training/trainees', [TrainingAdminController::class, 'storeTrainee']);
    Route::patch('/training/trainees/{id}', [TrainingAdminController::class, 'updateTrainee'])->whereNumber('id');
    Route::get('/training/products', [TrainingProductController::class, 'index']);
    Route::post('/training/products/scan', [TrainingProductController::class, 'scan']);
    Route::post('/training/products', [TrainingProductController::class, 'store']);
    Route::patch('/training/products/{id}', [TrainingProductController::class, 'update'])->whereNumber('id');
    Route::delete('/training/products/{id}', [TrainingProductController::class, 'destroy'])->whereNumber('id');
    Route::delete('/training/products', [TrainingProductController::class, 'clear']);
    Route::get('/reports/training', [TrainingReportController::class, 'index']);
});
Route::post('/json/training/reset', [TrainingAdminController::class, 'reset'])
    ->middleware(['auth', 'permission:manage-training']);
Route::middleware(['auth', 'permission:manage-training'])->group(function () {
    Route::get('/report/training/setup-packet.pdf', [TrainingAdminController::class, 'setupPacket']);
    Route::get('/report/training/signups.csv', [TrainingAdminController::class, 'signupsCsv']);
    Route::get('/report/training/signup-poster.pdf', [TrainingAdminController::class, 'signupPoster']);
    Route::get('/report/training/pallet-labels.pdf', [PalletReportController::class, 'trainingLabelsBuilder']);
    Route::get('/report/training/spot-labels.pdf', [TrainingAdminController::class, 'spotLabels']);
    Route::get('/report/training/archives/{stamp}/{file}', [TrainingAdminController::class, 'archiveFile']);
    Route::get('/report/training/report-cards.pdf', [TrainingReportController::class, 'pdf']);
    Route::get('/report/training/report-cards.csv', [TrainingReportController::class, 'csv']);
    Route::get('/report/training/certificates.pdf', [TrainingReportController::class, 'certificates']);
});
Route::get('/report/training/team-sheets.pdf', [TrainingAdminController::class, 'teamSheets'])->middleware(['auth']);
