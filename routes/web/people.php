<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * People, users and roles
 *
 * People (donors, partners, staff, volunteers), login-capable users, roles and permission overrides, and counties.
 *
 * Access: `manage-people`, `manage-users`, `manage-roles`, `manage-counties`; deleting a person is `admin-people`. Mailing labels
 * and the donor standing endpoint are in web/reports.php.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/setup/people', function () {
    return Inertia::render('People', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/people'),
        // DELETE /json/people/{id} is admin-people; only offer the button to someone it would work for.
        'canDelete' => (bool) auth()->user()?->hasPermission('admin-people'),
    ]);
})->middleware(['auth', 'permission:manage-people']);

// User Administration — create/promote/deactivate
// login-capable accounts. Distinct from /setup/people: that page manages
// party-tracking roles only, no permission overrides.
Route::get('/setup/users', function () {
    return Inertia::render('Users',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/users')]);
})->middleware(['auth', 'permission:manage-users']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-people']], function () {
    Route::get('/people', [PeopleController::class, 'index']);
    Route::post('/people', [PeopleController::class, 'store']);
    Route::put('/people/{id}', [PeopleController::class, 'update']);
    Route::post('/people/{id}/partner-status', [PeopleController::class, 'partnerStatus']);
    Route::get('/mailing-labels', [MailingLabelController::class, 'index']);
    // Read-only listing so the People edit form can offer per-person
    // permission overrides.
    Route::get('/permissions', [PermissionController::class, 'index']);
    // person_categories: a tightly-coupled sub-resource of People (the
    // open-ended party-type tag), not its own permission/admin page — see
    // person-tagging-and-org-contacts-design memory.
    Route::get('/person-categories', [PersonCategoryController::class, 'index']);
    Route::post('/person-categories', [PersonCategoryController::class, 'store']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-users']], function () {
    Route::get('/users', [UserAdminController::class, 'index']);
    Route::get('/users/export.csv', [UserAdminController::class, 'csv']);
    Route::post('/users', [UserAdminController::class, 'store']);
    Route::get('/users/invitations', [UserAdminController::class, 'invitations']);
    Route::post('/users/invitations/{id}/resend', [UserAdminController::class, 'resendInvitation']);
    Route::delete('/users/invitations/{id}', [UserAdminController::class, 'withdrawInvitation']);
    Route::post('/users/bulk', [UserAdminController::class, 'bulk']);
    Route::put('/users/{id}', [UserAdminController::class, 'update']);
    Route::post('/users/{id}/deactivate', [UserAdminController::class, 'deactivate']);
    Route::post('/users/{id}/reactivate', [UserAdminController::class, 'reactivate']);
    Route::post('/users/{id}/resend-invite', [UserAdminController::class, 'resendInvite']);
    Route::post('/users/{id}/approve', [UserAdminController::class, 'approve']);
    Route::post('/users/{id}/set-password', [UserAdminController::class, 'setPassword']);
    Route::post('/users/{id}/require-password-change', [UserAdminController::class, 'requirePasswordChange']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-roles']], function () {
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-counties']], function () {
    Route::post('/counties', [CountyController::class, 'store']);
    Route::put('/counties/{id}', [CountyController::class, 'update']);
    Route::delete('/counties/{id}', [CountyController::class, 'destroy']);
});

// Deleting a person is a records-integrity issue, not a permissions tier —
// no bundle grants this by default; kept separate from manage-people
// pending the Partner/Donor rework, which is expected to replace this with
// deactivate/hide rather than a hard delete (see dev/TODO.md, "Permissions and people").
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-people']], function () {
    Route::delete('/people/{id}', [PeopleController::class, 'destroy']);
});
