<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\FeedbackAggregationSetting;
use App\Models\MenuItem;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Warehouse administration
 *
 * Settings and tools for one warehouse's administrator: Warehouse Backups (/setup/system, restore), email settings, active sessions,
 * the testing tools, feedback reports and the site banner, and data import.
 *
 * Access: `admin-system`, `manage-backups`, `manage-feedback`, `manage-import`, `admin-import`. What belongs to the whole
 * instance is in web/instance.php. The /json/system/* update routes are the deprecated bare-metal web updater (dev/TODO.md).
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Warehouse Backups (/setup/system): backup zips, the backup schedule and the warehouse key. Each section is gated
// by its own permission (the JSON endpoints below enforce the same), so the page opens for anyone holding
// admin-system or manage-backups.
Route::get('/setup/system', function () {
    $user = Auth::user();
    $canAdmin = $user->hasPermission('admin-system');
    $canBackups = $user->hasPermission('manage-backups');
    abort_unless($canAdmin || $canBackups, 403, 'Missing required permission: admin-system');

    return Inertia::render('SystemAdmin', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/system'),
        'canAdminSystem' => $canAdmin,
        'canManageBackups' => $canBackups,
        'canViewAppKey' => $user->hasPermission('view-app-key'),
    ]);
})->middleware(['auth']);

// Restore a backup into this warehouse (RestoreController::owning(); the JSON half is the warehouse-restore group above).
Route::get('/setup/restore', function () {
    $user = Auth::user();
    abort_if(app(WarehouseContext::class)->current()->is_training, 404);

    return Inertia::render('Setup/WarehouseRestore', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/restore'),
        'warehouseName' => app(WarehouseContext::class)->current()->display_name,
        'instanceAdmin' => (bool) $user->account?->is_instance_admin,
    ]);
})->middleware(['auth', 'permission:admin-system']);

// Testing Tools: the warehouse simulator and the slow-query log (JSON half is in the admin-system group below).
Route::get('/setup/testing', [TestingToolsController::class, 'page'])->middleware(['auth', 'permission:admin-system']);

// Email Settings: outgoing mail + feedback recipients (JSON half is in the admin-system group below).
Route::get('/setup/email', function () {
    return Inertia::render('EmailSettings',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/email')]);
})->middleware(['auth', 'permission:admin-system']);

Route::get('/setup/active-sessions', function () {
    return Inertia::render('ActiveSessions',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/active-sessions')]);
})->middleware(['auth', 'permission:admin-system']);

Route::get('/setup/feedback', function () {
    return Inertia::render('FeedbackReports', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/feedback'),
        // Submitted/Not Sent badges + the "Send Unsent Reports" action are a
        // remote-instance-only concept — central's own local reports about
        // itself are trivially "submitted" (they don't go anywhere).
        'instanceRole' => FeedbackAggregationSetting::current()->instance_role,
    ]);
})->middleware(['auth', 'permission:manage-feedback']);

Route::get('/setup/banner', function () {
    return Inertia::render('SiteBanner',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/banner')]);
})->middleware(['auth', 'permission:manage-feedback']);

Route::get('/setup/import', function () {
    return Inertia::render('Imports',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/import')]);
})->middleware(['auth', 'permission:manage-import']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-feedback']], function () {
    Route::get('/feedback-reports', [FeedbackReportController::class, 'index']);
    Route::get('/feedback-reports/{feedbackReport}/screenshot', [FeedbackReportController::class, 'screenshot']);
    Route::patch('/feedback-reports/{feedbackReport}', [FeedbackReportController::class, 'update']);
    // The only retry mechanism for centralized feedback aggregation — see
    // FeedbackPushService::pushUnsent(). A no-op (but not an error) on a
    // 'central' instance or one that hasn't chosen an instance to send through.
    Route::post('/feedback-reports/send-unsent', [FeedbackReportController::class, 'sendUnsent']);
    Route::put('/banner-settings', [BannerSettingController::class, 'update']);
    // Admin-editable centralized feedback aggregation settings (instance
    // role, which paired instance to send through) — see
    // FeedbackAggregationSetting; gated the same as this whole page.
    Route::get('/feedback-aggregation-settings', [FeedbackAggregationSettingsController::class, 'show']);
    Route::put('/feedback-aggregation-settings', [FeedbackAggregationSettingsController::class, 'update']);
    Route::post('/feedback-aggregation-settings/test', [FeedbackAggregationSettingsController::class, 'test']);
});

// Import: upload/preview/commit are manage-import; viewing/deleting batch
// history is split into admin-import (both Administrator-only by default,
// same as manage-users/admin-system — a bad import has real blast radius).
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-import']], function () {
    Route::get('/imports/options', [ImportController::class, 'options']);
    Route::post('/imports', [ImportController::class, 'store']);
    Route::post('/imports/{id}/commit', [ImportController::class, 'commit']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-import']], function () {
    Route::get('/imports', [ImportController::class, 'index']);
    Route::get('/imports/{id}/rows', [ImportController::class, 'rows']);
    Route::delete('/imports/{id}', [ImportController::class, 'destroy']);
});

// System administration: software updates, backup inventory, and the backup
// schedule. The update/backup-now endpoints only start systemd units (via a
// sudoers rule scoped to exactly those units) — the heavy lifting happens in
// scripts/update.sh outside the request cycle.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/system/status', [SystemController::class, 'status']);
    Route::post('/system/check-updates', [SystemController::class, 'checkUpdates']);
    Route::post('/system/update', [SystemController::class, 'update']);
    Route::put('/system/offline-mode', [SystemController::class, 'saveOfflineMode']);

    Route::get('/testing/status', [TestingToolsController::class, 'status']);
    Route::post('/testing/simulation', [TestingToolsController::class, 'startSimulation']);
    Route::get('/testing/slow-queries', [TestingToolsController::class, 'slowQueries']);
    Route::delete('/testing/slow-queries', [TestingToolsController::class, 'clearSlowQueries']);

    Route::get('/active-sessions', [ActiveSessionController::class, 'index']);
    Route::get('/login-history', [ActiveSessionController::class, 'history']);
});

// Backup zips (database, or database + uploads): trigger, list, download, delete. The key panel is its own,
// narrower permission and a throttled POST — see BackupController::appKey. Their page is the Backups section
// of /setup/system now; the old address still works.
Route::redirect('/setup/backups', '/setup/system')->middleware('auth');

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-backups']], function () {
    Route::get('/backups', [BackupController::class, 'index']);
    Route::post('/backups', [BackupController::class, 'store']);
    Route::get('/backups/schedule', [BackupController::class, 'schedule']);
    Route::put('/backups/schedule', [BackupController::class, 'updateSchedule']);
    Route::post('/backups/{id}/restore', [BackupController::class, 'restoreSnapshot'])->whereNumber('id')->middleware('throttle:5,1');
    Route::get('/backups/{id}/download', [BackupController::class, 'download'])->whereNumber('id');
    Route::delete('/backups/{id}', [BackupController::class, 'destroy']);
});
Route::post('/json/backups/app-key', [BackupController::class, 'appKey'])
    ->middleware(['auth', 'permission:view-app-key', 'throttle:5,1']);

// Email Settings (/setup/email) — system-wide config, same gate as the page.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/mail-settings', [MailSettingsController::class, 'show']);
    Route::put('/mail-settings', [MailSettingsController::class, 'update']);
    Route::post('/mail-settings/test', [MailSettingsController::class, 'test'])->middleware('throttle:6,1');
});
