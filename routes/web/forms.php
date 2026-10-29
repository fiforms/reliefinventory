<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Forms and design votes
 *
 * Custom forms: building them, reviewing submissions, and the public pages that take them (/forms/{slug}); and the public design
 * votes (/vote/{slug}) with their admin page.
 *
 * Access: `manage-forms`, `review-form-submissions`, `manage-design-votes`. The public pages enforce the form's access mode in
 * their controller, so they have no `auth` middleware.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/setup/forms', function () {
    return Inertia::render('Forms',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/forms')]);
})->middleware(['auth', 'permission:manage-forms']);

// Review lives under manage-forms's own menu item rather than a second
// top-level nav entry — same "no new nav item" choice as Donation Offers
// living inside Receiving — but is gated separately (review-form-
// submissions), matching manage-donation-offers's split from
// manage-receiving.
Route::get('/setup/forms/{id}/submissions', function ($id) {
    return Inertia::render('FormSubmissions',
        ['formId' => (int) $id, 'breadcrumb' => MenuItem::getBreadcrumb('/setup/forms')]);
})->middleware(['auth', 'permission:review-form-submissions']);

// Design Votes (DesignVoteController, App\Support\DesignPolls): public, so trainees and partners can vote without an
// account. Same room-on-one-IP limit as /join; Turnstile keeps bots out.
Route::get('/vote/{slug}', [DesignVoteController::class, 'show'])->name('design-vote');
Route::post('/vote/{slug}', [DesignVoteController::class, 'store'])->middleware('throttle:80,1');
Route::post('/vote/{slug}/idea', [DesignVoteController::class, 'storeIdea'])->middleware('throttle:80,1');
Route::get('/setup/design-votes', function () {
    return Inertia::render('Setup/DesignVotes', ['breadcrumb' => MenuItem::getBreadcrumb('/setup/design-votes')]);
})->middleware(['auth', 'permission:manage-design-votes']);
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-design-votes']], function () {
    Route::get('/design-votes', [DesignVoteController::class, 'status']);
    Route::put('/design-votes/{slug}/open', [DesignVoteController::class, 'setOpen']);
    Route::get('/design-votes/{slug}/csv', [DesignVoteController::class, 'csv']);
});

// The public/staff submission page — no 'auth' middleware, since the same
// route serves an anonymous prospective partner and a logged-in staffer.
// PublicFormController itself enforces the form's access_mode.
Route::get('/forms/{slug}', [PublicFormController::class, 'show'])->name('forms.show');
Route::post('/forms/{slug}', [PublicFormController::class, 'submit'])->name('forms.submit');

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-forms']], function () {
    Route::get('/forms', [FormController::class, 'index']);
    Route::post('/forms', [FormController::class, 'store']);
    Route::get('/forms/presets', [FormController::class, 'presets']);
    Route::get('/forms/{form}', [FormController::class, 'show']);
    Route::put('/forms/{form}', [FormController::class, 'update']);
    Route::delete('/forms/{form}', [FormController::class, 'destroy']);
    Route::post('/forms/{form}/questions', [FormController::class, 'storeQuestion']);
    Route::post('/forms/{form}/questions/add-presets', [FormController::class, 'addPresets']);
    Route::post('/forms/{form}/questions/reorder', [FormController::class, 'reorderQuestions']);
    Route::put('/forms/{form}/questions/{question}', [FormController::class, 'updateQuestion']);
    Route::delete('/forms/{form}/questions/{question}', [FormController::class, 'destroyQuestion']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:review-form-submissions']], function () {
    Route::get('/forms/{form}/submissions', [FormSubmissionController::class, 'index']);
    Route::get('/forms/{form}/submissions/{submission}', [FormSubmissionController::class, 'show']);
    Route::post('/forms/{form}/submissions/{submission}/approve', [FormSubmissionController::class, 'approve']);
    Route::post('/forms/{form}/submissions/{submission}/deny', [FormSubmissionController::class, 'deny']);
    Route::post('/forms/{form}/submissions/{submission}/note', [FormSubmissionController::class, 'addNote']);
});
