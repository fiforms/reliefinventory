<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Reports and dashboards
 *
 * Report pages and their /json data, the PDF and CSV downloads (/report/*), the internal dashboard and the Situation Report. See
 * dev/notes/REPORTS.md.
 *
 * Access: `view-reports` for most; `view-dashboard`, `view-sitrep`, and `manage-people` for mailing labels. The always-on display
 * versions of the dashboard and sitrep are in web/public.php.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

Route::get('/reports/inventory', function () {
    return Inertia::render('InventoryReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/inventory')]);
})->middleware(['auth', 'permission:view-reports']);

Route::get('/reports/orders', function () {
    return Inertia::render('OutstandingOrdersReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/orders')]);
})->middleware(['auth', 'permission:view-reports']);

Route::get('/reports/flow', function () {
    return Inertia::render('FlowReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/flow')]);
})->middleware(['auth', 'permission:view-reports']);

// Mailing labels (MailingLabelController): names and addresses, so manage-people, not view-reports.
Route::get('/reports/mailing-labels', function () {
    return Inertia::render('MailingLabels',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/mailing-labels')]);
})->middleware(['auth', 'permission:manage-people']);

Route::get('/reports/partners', function () {
    return Inertia::render('PartnerReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/partners')]);
})->middleware(['auth', 'permission:view-reports']);

Route::get('/reports/donors', function () {
    return Inertia::render('DonorReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/donors')]);
})->middleware(['auth', 'permission:view-reports']);

Route::get('/reports/unfulfilled', function () {
    return Inertia::render('UnfulfilledRequestsReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/unfulfilled')]);
})->middleware(['auth', 'permission:view-reports']);

Route::get('/reports/sorting-area', function () {
    return Inertia::render('SortingAreaReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/sorting-area')]);
})->middleware(['auth', 'permission:view-reports']);

// Internal warehouse dashboard: full-detail view for management/admins.
Route::get('/reports/dashboard', function () {
    return Inertia::render('WarehouseDashboard',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/dashboard')]);
})->middleware(['auth', 'permission:view-dashboard']);

// External Situation Report: restricted subset of the same data, meant to
// be shared outside the organization (FEMA/state liaisons) — see
// SitrepController for what's deliberately left out.
Route::get('/reports/sitrep', function () {
    return Inertia::render('SituationReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/sitrep')]);
})->middleware(['auth', 'permission:view-sitrep']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:view-reports']], function () {
    Route::get('/reports/inventory', [InventoryReportController::class, 'index']);
    Route::get('/reports/orders', [OutstandingOrdersReportController::class, 'index']);
    Route::get('/reports/unfulfilled', [UnfulfilledRequestsReportController::class, 'index']);
    Route::get('/reports/flow', [FlowReportController::class, 'index']);
    Route::get('/reports/partners', [PartnerReportController::class, 'index']);
    Route::get('/reports/donors', [DonorReportController::class, 'index']);
    Route::get('/reports/sorting-area', [SortingAreaReportController::class, 'index']);
});

Route::put('/json/people/{id}/donor-standing', [DonorReportController::class, 'setStanding'])
    ->middleware(['auth', 'permission:manage-donor-standing']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:view-dashboard']], function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:view-sitrep']], function () {
    Route::get('/sitrep', [SitrepController::class, 'index']);
});

// PDF export of the Situation Report — separate route (not under /json)
// since it returns a binary download, matching PalletReportController's
// pattern for the other PDF endpoints in this app.
Route::get('/report/sitrep.pdf', [SitrepController::class, 'pdf'])
    ->name('report.sitrep')
    ->middleware(['auth', 'permission:view-sitrep']);

Route::get('/report/inventory.pdf', [InventoryReportController::class, 'pdf'])
    ->name('report.inventory')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/inventory.csv', [InventoryReportController::class, 'csv'])
    ->name('report.inventory.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/orders.pdf', [OutstandingOrdersReportController::class, 'pdf'])
    ->name('report.orders')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/orders.csv', [OutstandingOrdersReportController::class, 'csv'])
    ->name('report.orders.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/flow.pdf', [FlowReportController::class, 'pdf'])
    ->name('report.flow')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/flow.csv', [FlowReportController::class, 'csv'])
    ->name('report.flow.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/mailing-labels.pdf', [MailingLabelController::class, 'pdf'])
    ->name('report.mailing-labels')
    ->middleware(['auth', 'permission:manage-people']);

Route::get('/report/partners.pdf', [PartnerReportController::class, 'pdf'])
    ->name('report.partners')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/partners.csv', [PartnerReportController::class, 'csv'])
    ->name('report.partners.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/donors.pdf', [DonorReportController::class, 'pdf'])
    ->name('report.donors')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/donors.csv', [DonorReportController::class, 'csv'])
    ->name('report.donors.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/unfulfilled.pdf', [UnfulfilledRequestsReportController::class, 'pdf'])
    ->name('report.unfulfilled')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/sorting-area.csv', [SortingAreaReportController::class, 'csv'])
    ->name('report.sorting-area.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/unfulfilled.csv', [UnfulfilledRequestsReportController::class, 'csv'])
    ->name('report.unfulfilled.csv')
    ->middleware(['auth', 'permission:view-reports']);
