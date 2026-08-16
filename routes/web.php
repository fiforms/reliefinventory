<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
        'hostedBy' => config('app.hosted_by'),
        'hostedLink' => config('app.hosted_link'),
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/qrcode-test', function () {
    return Inertia::render('QRCodeTest');
})->middleware(['auth', 'verified']);

// Data behind this page (orders/people/items) all require manage-orders
// or above — the page itself used to be auth-only, letting a
// permission-less user load a page whose data would just fail to load.
Route::get('/order-entry', function () {
    return Inertia::render('OrderEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/order-entry')]);
})->middleware(['auth', 'permission:manage-orders']);

Route::get('/receiving', function () {
    return Inertia::render('Receiving',
        ['breadcrumb' => MenuItem::getBreadcrumb('/receiving')]);
})->middleware(['auth', 'permission:manage-receiving']);

Route::get('/donation-sorting', function () {
    return Inertia::render('DonationSorting',
        ['breadcrumb' => MenuItem::getBreadcrumb('/donation-sorting')]);
})->middleware(['auth', 'permission:manage-sorting']);

Route::get('/inventory-movement', function () {
    return Inertia::render('PalletLocation',
        ['breadcrumb' => MenuItem::getBreadcrumb('/inventory-movement')]);
})->middleware(['auth', 'permission:manage-pallets']);

Route::get('/pallet-management', function () {
    return Inertia::render('PalletManagement',
        ['breadcrumb' => MenuItem::getBreadcrumb('/pallet-management')]);
})->middleware(['auth', 'permission:manage-pallets']);

Route::get('/setup/items', function () {
    return Inertia::render('ItemEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/items')]);
})->middleware(['auth', 'permission:manage-items']);

Route::get('/setup/people', function () {
    return Inertia::render('People',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/people')]);
})->middleware(['auth', 'permission:manage-people']);

Route::get('/setup/categories', function () {
    return Inertia::render('CategoryEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/categories')]);
})->middleware(['auth', 'permission:manage-categories']);

Route::get('/setup/locations', function () {
    return Inertia::render('LocationEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/locations')]);
})->middleware(['auth', 'permission:manage-locations']);

Route::get('/setup/system', function () {
    return Inertia::render('SystemAdmin',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/system')]);
})->middleware(['auth', 'permission:admin-system']);

// Gated loosely at the route level (general-access — the page itself
// conditionally shows the Settings section for admin-system holders and
// the Trusted Devices section for manage-trusted-devices holders, since
// those are two independently delegable permissions with no OR-gate
// primitive in CheckPermission to express "either one"). Each underlying
// /json endpoint stays strictly gated on its own permission regardless —
// this route-level looseness only controls whether the page loads at all.
Route::get('/setup/pin-login', function () {
    $user = Auth::user();

    return Inertia::render('PinLoginSettings', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/pin-login'),
        'canManageSettings' => $user->hasPermission('admin-system'),
        'canManageDevices' => $user->hasPermission('manage-trusted-devices'),
    ]);
})->middleware(['auth', 'permission:general-access']);

Route::get('/reports/labels', function () {
    return Inertia::render('PrintLabels',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/labels')]);
})->middleware(['auth', 'permission:manage-items']);

Route::get('/report/pallet/{id}',
    [PalletReportController::class, 'generateReport'])
    ->name('report.pallet')
    ->middleware(['auth', 'permission:manage-pallets']);

// Batch label print for a whole donation, used from the Receiving page —
// gated on manage-receiving to match the page that offers it.
Route::get('/report/pallets/donation/{id}',
    [PalletReportController::class, 'generateDonationLabels'])
    ->name('report.pallets.donation')
    ->middleware(['auth', 'permission:manage-receiving']);

Route::get('/reports/inventory', function () {
    return Inertia::render('InventoryReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/inventory')]);
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

// Roadmap pages linked from the main menu but not yet built.
// Renders a "coming soon" placeholder instead of 404ing.
foreach ([
    '/order-filling' => 'Order Filling',
    '/reports/orders' => 'Outstanding Orders Report',
    '/reports/flow' => 'Inventory Flow Report',
    '/reports/donors' => 'Donor Report',
    '/reports/customers' => 'Customer Report',
    '/setup/users' => 'User Management',
] as $path => $feature) {
    Route::get($path, function () use ($path, $feature) {
        return Inertia::render('ComingSoon', [
            'breadcrumb' => MenuItem::getBreadcrumb($path),
            'feature' => $feature,
        ]);
    })->middleware(['auth', 'permission:general-access']);
}

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Routes accessible  by everyone (including unknown users who just registered with an email address)
Route::group(['prefix' => 'json', 'middleware' => ['auth']], function () {
    Route::get('/menu-data', [MenuController::class, 'index']);

    // API route for listing all statuses
    Route::get('/statuses', [StatusController::class, 'index']);

    Route::get('/counties', [CountyController::class, 'index']);
    Route::get('/states', [CountyController::class, 'states']);

});

// Below: routes grouped by the permission they require rather than a
// single flat "volunteer tier" — see granular-permissions-model.md.
// Every group here was previously role:4; splitting by resource is
// what actually makes a per-person permission override (e.g. granting
// one volunteer manage-people without making them a Team Leader) mean
// something, instead of an all-or-nothing tier under a new name.

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-people']], function () {
    Route::get('/people', [PeopleController::class, 'index']);
    Route::post('/people', [PeopleController::class, 'store']);
    Route::put('/people/{id}', [PeopleController::class, 'update']);
    // Read-only listing so the People edit form can offer per-person
    // permission overrides.
    Route::get('/permissions', [PermissionController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-orders']], function () {
    // Order intake sessions (header created at customer confirm; requested
    // lines autosave one at a time — see OrderController)
    Route::get('/orders', [OrderController::class, 'index']);
    // before /orders/{id} so "stock-hints" isn't captured as an id
    Route::get('/orders/stock-hints', [OrderController::class, 'stockHints']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}', [OrderController::class, 'update']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
    Route::post('/orders/{id}/lines', [OrderController::class, 'storeLine']);
    Route::put('/orders/{id}/lines/{lineId}', [OrderController::class, 'updateLine']);
    Route::delete('/orders/{id}/lines/{lineId}', [OrderController::class, 'destroyLine']);

    // Dead code (no consuming Vue page — SortingSessionController owns
    // donation creation now), left routed rather than silently
    // orphaned; shares orders' permission since it's the same table.
    Route::get('/donations', [DonationController::class, 'index']);
    Route::post('/donations', [DonationController::class, 'store']);
    Route::put('/donations/{id}', [DonationController::class, 'update']);
    Route::delete('/donations/{id}', [DonationController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-items']], function () {
    Route::get('/items', [ItemController::class, 'index']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::put('/items/{id}', [ItemController::class, 'update']);
    Route::delete('/items/{id}', [ItemController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-units']], function () {
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);
    Route::put('/units/{id}', [UnitController::class, 'update']);
    Route::delete('/units/{id}', [UnitController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-categories']], function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    // Sorters may add new categories on the fly when unfamiliar goods
    // arrive (update/delete remain admin-only below)
    Route::post('/categories', [CategoryController::class, 'store']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-locations']], function () {
    Route::get('/locations', [LocationController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-warehouses']], function () {
    Route::get('/warehouses', [WarehouseController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-uses']], function () {
    Route::get('/uses', [UseController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-pallets']], function () {
    // Pallet Status (history/audit trail for pallets)
    Route::get('/palletstatus', [PalletStatusController::class, 'index']);
    Route::get('/palletstatus/statuses', [PalletStatusController::class, 'statuses']);
    Route::post('/palletstatus', [PalletStatusController::class, 'store']);
    Route::put('/palletstatus/{id}', [PalletStatusController::class, 'update']);
    Route::delete('/palletstatus/{id}', [PalletStatusController::class, 'destroy']);

    // Pallets (five-kind model: Receiving/Warehouse/Shipping/Hold/Quarantine)
    Route::get('/pallets', [PalletController::class, 'index']);
    Route::get('/pallets/{kind}', [PalletController::class, 'index']);
    Route::get('/pallets/{kind}/{status}', [PalletController::class, 'index']);
    Route::post('/pallets', [PalletController::class, 'store']);
    Route::put('/pallets/{id}', [PalletController::class, 'update']);
    Route::delete('/pallets/{id}', [PalletController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-itemtypes']], function () {
    Route::get('/itemtypes', [ItemTypeController::class, 'index']);
    Route::get('/itemtypes/{mod}', [ItemTypeController::class, 'index']);
    // Sorters may add new item types on the fly (update/delete remain admin-only below)
    Route::post('/itemtypes', [ItemTypeController::class, 'store']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:view-reports']], function () {
    Route::get('/reports/inventory', [InventoryReportController::class, 'index']);
});

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

// Offline order form (in-stock item types only, no quantities) — printed or
// emailed to a POD/customer, then hand-keyed back in as an order.
Route::get('/report/order-form.pdf', [OrderController::class, 'orderFormPdf'])
    ->name('report.order-form')
    ->middleware(['auth', 'permission:manage-orders']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-packagetypes']], function () {
    Route::get('/packagetypes', [PackageTypeController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-sorting']], function () {
    // Sorting sessions (scan-driven donation sorting; lines autosave one at a time)
    Route::get('/sorting-sessions', [SortingSessionController::class, 'index']);
    Route::post('/sorting-sessions', [SortingSessionController::class, 'store']);
    Route::get('/sorting-sessions/pallet/{tag}', [SortingSessionController::class, 'pallet']);
    Route::post('/sorting-sessions/pallet/{tag}/empty', [SortingSessionController::class, 'palletEmpty']);
    Route::get('/sorting-sessions/{id}', [SortingSessionController::class, 'show']);
    Route::patch('/sorting-sessions/{id}', [SortingSessionController::class, 'update']);
    Route::post('/sorting-sessions/{id}/lines', [SortingSessionController::class, 'storeLine']);
    Route::put('/sorting-sessions/{id}/lines/{lineId}', [SortingSessionController::class, 'updateLine']);
    Route::delete('/sorting-sessions/{id}/lines/{lineId}', [SortingSessionController::class, 'destroyLine']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-receiving']], function () {
    Route::get('/receiving', [ReceivingController::class, 'index']);
    Route::post('/receiving', [ReceivingController::class, 'store']);
    Route::put('/receiving/{id}', [ReceivingController::class, 'update']);
    Route::delete('/receiving/{id}', [ReceivingController::class, 'destroy']);
    Route::post('/receiving/{id}/pallets', [ReceivingController::class, 'createPallets']);
    Route::post('/receiving/{id}/close-out', [ReceivingController::class, 'closeOut']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-trucks']], function () {
    Route::get('/trucks', [TruckController::class, 'index']);
    Route::post('/trucks', [TruckController::class, 'store']);
    Route::put('/trucks/{id}', [TruckController::class, 'update']);
    Route::delete('/trucks/{id}', [TruckController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-containers']], function () {
    Route::get('/containers', [ContainerController::class, 'index']);
    Route::post('/containers', [ContainerController::class, 'store']);
    Route::put('/containers/{id}', [ContainerController::class, 'update']);
    Route::delete('/containers/{id}', [ContainerController::class, 'destroy']);
    Route::get('/container-types', [ContainerTypeController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-streams']], function () {
    Route::get('/streams', [StreamController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-roles']], function () {
    Route::get('/roles', [RoleController::class, 'index']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-counties']], function () {
    Route::post('/counties', [CountyController::class, 'store']);
    Route::put('/counties/{id}', [CountyController::class, 'update']);
    Route::delete('/counties/{id}', [CountyController::class, 'destroy']);
});

// Destructive/structural ops — previously role:32768 (Administrator).
// Administrator holds every admin-* permission by default (see
// PermissionsSeeder), so this preserves today's effective access while
// making each capability individually grantable/revocable per person.

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-people']], function () {
    Route::delete('/people/{id}', [PeopleController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-categories']], function () {
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-locations']], function () {
    Route::post('/locations', [LocationController::class, 'store']);
    Route::put('/locations/{id}', [LocationController::class, 'update']);
    Route::delete('/locations/{id}', [LocationController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-warehouses']], function () {
    Route::post('/warehouses', [WarehouseController::class, 'store']);
    Route::put('/warehouses/{id}', [WarehouseController::class, 'update']);
    Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-uses']], function () {
    Route::post('/uses', [UseController::class, 'store']);
    Route::put('/uses/{id}', [UseController::class, 'update']);
    Route::delete('/uses/{id}', [UseController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-itemtypes']], function () {
    Route::put('/itemtypes/{id}', [ItemTypeController::class, 'update']);
    Route::delete('/itemtypes/{id}', [ItemTypeController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-packagetypes']], function () {
    Route::post('/packagetypes', [PackageTypeController::class, 'store']);
    Route::put('/packagetypes/{id}', [PackageTypeController::class, 'update']);
    Route::delete('/packagetypes/{id}', [PackageTypeController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-roles']], function () {
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-containers']], function () {
    Route::post('/container-types', [ContainerTypeController::class, 'store']);
    Route::put('/container-types/{id}', [ContainerTypeController::class, 'update']);
    Route::delete('/container-types/{id}', [ContainerTypeController::class, 'destroy']);
});

// System administration: software updates, backup inventory, and the backup
// schedule. The update/backup-now endpoints only start systemd units (via a
// sudoers rule scoped to exactly those units) — the heavy lifting happens in
// scripts/update.sh outside the request cycle.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/system/status', [SystemController::class, 'status']);
    Route::post('/system/check-updates', [SystemController::class, 'checkUpdates']);
    Route::post('/system/update', [SystemController::class, 'update']);
    Route::post('/system/backup', [SystemController::class, 'backupNow']);
    Route::put('/system/backup-settings', [SystemController::class, 'saveBackupSettings']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-streams']], function () {
    Route::post('/streams', [StreamController::class, 'store']);
    Route::put('/streams/{id}', [StreamController::class, 'update']);
    Route::delete('/streams/{id}', [StreamController::class, 'destroy']);
});

// PIN-login global settings (on/off, trust mode) — system-wide config,
// same gate as every other system-wide toggle.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/pin-login-settings', [PinLoginSettingsController::class, 'show']);
    Route::put('/pin-login-settings', [PinLoginSettingsController::class, 'update']);
});

// Which specific devices may use PIN unlock — deliberately a narrower,
// separately-delegable permission from admin-system (see PermissionsSeeder).
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-trusted-devices']], function () {
    Route::get('/trusted-devices', [TrustedDeviceController::class, 'index']);
    Route::post('/trusted-devices/{id}/approve', [TrustedDeviceController::class, 'approve']);
    Route::post('/trusted-devices/{id}/revoke', [TrustedDeviceController::class, 'revoke']);
    Route::put('/trusted-devices/{id}', [TrustedDeviceController::class, 'relabel']);
});

require __DIR__.'/auth.php';
