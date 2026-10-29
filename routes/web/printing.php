<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Print queue
 *
 * Sending a document to a site printer and the print queue screen and its jobs. See dev/notes/PRINT_QUEUE.md. Printer setup and
 * the device fleet are in web/devices.php.
 *
 * Access: `print-documents` to look and print; `manage-print-queue` to change a job.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Send a document to a site printer (renders it and queues it for a print
// server). The document's own permission (the one its download route needs)
// is also enforced inside PrintController — see DocumentRegistry.
Route::post('/json/print', [PrintController::class, 'store'])
    ->middleware(['auth', 'permission:print-documents']);
Route::get('/json/printers', [PrinterController::class, 'index'])
    ->middleware(['auth', 'permission:print-documents']);

// Print queue screen. Looking is print-documents (your own jobs; everyone's
// with manage-print-queue — enforced in PrintJobController); changing a job
// is manage-print-queue.
Route::get('/print-queue', function () {
    return Inertia::render('PrintQueue',
        ['breadcrumb' => MenuItem::getBreadcrumb('/print-queue')]);
})->middleware(['auth', 'permission:print-documents']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:print-documents']], function () {
    Route::get('/print-jobs', [PrintJobController::class, 'index']);
    Route::get('/print-jobs/{id}', [PrintJobController::class, 'show'])->whereNumber('id');
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-print-queue']], function () {
    Route::post('/print-jobs/clear', [PrintJobController::class, 'clear']);
    // Print any PDF an administrator uploads (the Printers page's "Print a PDF").
    Route::post('/print-upload', [PrintController::class, 'storeUpload']);
    Route::post('/print-jobs/{id}/cancel', [PrintJobController::class, 'cancel'])->whereNumber('id');
    Route::post('/print-jobs/{id}/retry', [PrintJobController::class, 'retry'])->whereNumber('id');
    Route::post('/print-jobs/{id}/divert', [PrintJobController::class, 'divert'])->whereNumber('id');
});
