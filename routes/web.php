<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskLocation;
use App\Models\KioskSetting;
use App\Models\MenuItem;
use App\Services\PinLoginService;
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

// Linked from Welcome.vue's HOSTED_BY credit line — public, no auth, same
// as Welcome itself.
Route::get('/about', function () {
    return Inertia::render('About');
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

Route::get('/order-filling', function () {
    return Inertia::render('OrderFilling',
        ['breadcrumb' => MenuItem::getBreadcrumb('/order-filling')]);
})->middleware(['auth', 'permission:manage-orders']);

Route::get('/shipping', function () {
    return Inertia::render('Shipping',
        ['breadcrumb' => MenuItem::getBreadcrumb('/shipping')]);
})->middleware(['auth', 'permission:manage-orders']);

// Driver Portal — deliberately NOT auth-gated: a driver has no account (see
// Driver's doc comment) and signs in with just phone + PIN, handled inside
// DriverPortalController itself. A staff visitor with manage-orders sees an
// admin read-only view of the same data instead — see the controller's doc
// comment for the full dual-audience design.
Route::get('/driver-portal', [DriverPortalController::class, 'page']);
Route::post('/driver-portal/login', [DriverPortalController::class, 'login'])->middleware('throttle:6,1');
Route::post('/driver-portal/logout', [DriverPortalController::class, 'logout']);
Route::get('/driver-portal/loads', [DriverPortalController::class, 'loads']);
Route::post('/driver-portal/loads/{id}/bol', [DriverPortalController::class, 'uploadBol']);

Route::get('/receiving', function () {
    return Inertia::render('Receiving',
        ['breadcrumb' => MenuItem::getBreadcrumb('/receiving')]);
})->middleware(['auth', 'permission:manage-receiving']);

// Offers live inside Receiving, not as their own top-level nav item — no
// new MenuItem, just a nested URL reusing Receiving's breadcrumb entry.
// Page-level gate stays the loose manage-receiving (viewing/logging a call
// is fine for anyone who does intake); the decision endpoints below enforce
// the narrower manage-donation-offers.
Route::get('/receiving/offers', function () {
    return Inertia::render('DonationOffers',
        ['breadcrumb' => MenuItem::getBreadcrumb('/receiving')]);
})->middleware(['auth', 'permission:manage-receiving']);

// kiosk-access (not auth+permission) so a device in kiosk mode can reach
// this page with nobody logged in — see EnsureKioskAccess. getBreadcrumb()
// needs a real MenuItem row regardless of auth state, which exists.
Route::get('/volunteers/kiosk', function () {
    // Location comes from THIS device (set when kiosk mode was enabled on
    // it), never a single global setting — falls back to the sole active
    // location if there's only one and this device hasn't been assigned
    // one yet (e.g. an operator viewing the page before ever enabling
    // kiosk mode here).
    $device = app(PinLoginService::class)->deviceFromCookie(request());
    $activeLocations = KioskLocation::where('active', true)->get();
    $location = $device?->kioskLocation ?: ($activeLocations->count() === 1 ? $activeLocations->first() : null);

    return Inertia::render('VolunteerKiosk', [
        'breadcrumb' => Auth::check() ? MenuItem::getBreadcrumb('/volunteers/kiosk') : [],
        'kioskLocationId' => $location?->id,
        'kioskLocationName' => $location?->name,
        'kioskWelcomeMessage' => $location?->welcome_message,
        'idleResetMinutes' => KioskSetting::current()->idle_reset_minutes,
        // ?closeout=1 (a device just coming out of kiosk lock via login/PIN
        // unlock — see PinLoginService::clearKioskMode) surfaces the
        // "Confirm Building Empty" action as a suggestion, not a forced step.
        'showCloseoutPrompt' => request()->boolean('closeout'),
    ]);
})->middleware(['kiosk-access']);

// No MenuItem/breadcrumb — reached only via the profile-menu quick-access
// link (see AuthenticatedLayout.vue), not the main nav, since it needs to
// be reachable from wherever someone happens to be in the app.
Route::get('/building-safety', function () {
    return Inertia::render('BuildingSafety');
})->middleware(['auth', 'permission:view-building-occupancy']);

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

// User Administration (TODO.md item 1) — create/promote/deactivate
// login-capable accounts. Distinct from /setup/people: that page manages
// party-tracking roles only, no permission overrides.
Route::get('/setup/users', function () {
    return Inertia::render('Users',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/users')]);
})->middleware(['auth', 'permission:manage-users']);

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

Route::get('/setup/active-sessions', function () {
    return Inertia::render('ActiveSessions',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/active-sessions')]);
})->middleware(['auth', 'permission:admin-system']);

Route::get('/setup/kiosk-settings', function () {
    return Inertia::render('KioskSettings',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/kiosk-settings')]);
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

Route::get('/setup/feedback', function () {
    return Inertia::render('FeedbackReports',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/feedback')]);
})->middleware(['auth', 'permission:manage-feedback']);

Route::get('/setup/banner', function () {
    return Inertia::render('SiteBanner',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/banner')]);
})->middleware(['auth', 'permission:manage-feedback']);

Route::get('/setup/import', function () {
    return Inertia::render('Imports',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/import')]);
})->middleware(['auth', 'permission:manage-import']);

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

// The public/staff submission page — no 'auth' middleware, since the same
// route serves an anonymous prospective partner and a logged-in staffer.
// PublicFormController itself enforces the form's access_mode.
Route::get('/forms/{slug}', [PublicFormController::class, 'show'])->name('forms.show');
Route::post('/forms/{slug}', [PublicFormController::class, 'submit'])->name('forms.submit');

// Help pages: static how-to guides, one per warehouse stage. Visible to
// anyone authenticated (no permission gate) — the menu item that links here
// has no permission_key either, see 2026_08_18_170000_add_help_menu.php.
Route::get('/help/receiving', function () {
    return Inertia::render('Help/Receiving',
        ['breadcrumb' => MenuItem::getBreadcrumb('/help/receiving')]);
})->middleware(['auth']);

Route::get('/help/sorting', function () {
    return Inertia::render('Help/Sorting',
        ['breadcrumb' => MenuItem::getBreadcrumb('/help/sorting')]);
})->middleware(['auth']);

// Printable PDF version of a help guide — same no-permission-gate rule as
// the guide pages themselves. Route/controller named generically so more
// guides (e.g. /report/help/sorting) can be added without redesigning this.
Route::get('/report/help/receiving',
    [HelpReportController::class, 'receiving'])
    ->name('report.help.receiving')
    ->middleware(['auth']);

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

Route::get('/reports/orders', function () {
    return Inertia::render('OutstandingOrdersReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/orders')]);
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
    '/reports/flow' => 'Inventory Flow Report',
    '/reports/donors' => 'Donor Report',
    '/reports/partners' => 'Partner Report',
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
    Route::post('/people/{id}/partner-status', [PeopleController::class, 'partnerStatus']);
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
    Route::post('/users', [UserAdminController::class, 'store']);
    Route::put('/users/{id}', [UserAdminController::class, 'update']);
    Route::post('/users/{id}/deactivate', [UserAdminController::class, 'deactivate']);
    Route::post('/users/{id}/reactivate', [UserAdminController::class, 'reactivate']);
    Route::post('/users/{id}/resend-invite', [UserAdminController::class, 'resendInvite']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-orders']], function () {
    // Order intake sessions (header created at partner confirm; requested
    // lines autosave one at a time — see OrderController). Routes/permission
    // key/DB stay "order" — partner-facing UI shows "Request" instead, see
    // OrderController's doc comment.
    Route::get('/orders', [OrderController::class, 'index']);
    // before /orders/{id} so "stock-hints" isn't captured as an id
    Route::get('/orders/stock-hints', [OrderController::class, 'stockHints']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}', [OrderController::class, 'update']);
    Route::patch('/orders/{id}/complete', [OrderController::class, 'complete']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
    Route::post('/orders/{id}/lines', [OrderController::class, 'storeLine']);
    Route::put('/orders/{id}/lines/{lineId}', [OrderController::class, 'updateLine']);
    Route::delete('/orders/{id}/lines/{lineId}', [OrderController::class, 'destroyLine']);

    // Order Filling/Picking — the order (Transaction) itself is the filling
    // session, same as donation sorting; see OrderFillingController's doc
    // comment. Fill records are ItemLedger rows nested under an order line.
    Route::get('/order-filling', [OrderFillingController::class, 'index']);
    Route::patch('/order-filling/{id}/start', [OrderFillingController::class, 'start']);
    Route::post('/order-filling/print-pick-sheets', [OrderFillingController::class, 'printPickSheets']);
    Route::patch('/order-filling/{id}/complete', [OrderFillingController::class, 'completeFilling']);
    Route::post('/order-filling/{id}/lines/{lineId}/fills', [OrderFillingController::class, 'storeFill']);
    Route::put('/order-filling/{id}/lines/{lineId}/fills/{fillId}', [OrderFillingController::class, 'updateFill']);
    Route::delete('/order-filling/{id}/lines/{lineId}/fills/{fillId}', [OrderFillingController::class, 'destroyFill']);

    // Shipping — Filled -> Ready to Ship (driver assigned) -> Shipped
    // (staff-confirmed departure); see ShippingController's doc comment.
    // Delivered is set separately, by DriverPortalController.
    Route::get('/shipping', [ShippingController::class, 'index']);
    Route::patch('/shipping/{id}/assign', [ShippingController::class, 'assign']);
    Route::patch('/shipping/{id}/mark-shipped', [ShippingController::class, 'markShipped']);
    Route::get('/shipping/{id}/signed-bol', [ShippingController::class, 'signedBol']);
    Route::post('/shipping/{id}/approve', [ShippingController::class, 'approve']);
    Route::post('/shipping/{id}/reject', [ShippingController::class, 'reject']);

    // Driver Portal PIN — how a driver gets into /driver-portal. Kept under
    // manage-orders (Shipping's own permission) rather than manage-receiving
    // like the rest of DriverController, since this is a shipping concern.
    Route::post('/drivers/{driver}/set-pin', [DriverController::class, 'setPin']);

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
    Route::get('/reports/orders', [OutstandingOrdersReportController::class, 'index']);
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

Route::get('/report/inventory.csv', [InventoryReportController::class, 'csv'])
    ->name('report.inventory.csv')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/orders.pdf', [OutstandingOrdersReportController::class, 'pdf'])
    ->name('report.orders')
    ->middleware(['auth', 'permission:view-reports']);

Route::get('/report/orders.csv', [OutstandingOrdersReportController::class, 'csv'])
    ->name('report.orders.csv')
    ->middleware(['auth', 'permission:view-reports']);

// Offline order form (in-stock item types only, no quantities) — printed or
// emailed to a POD/partner, then hand-keyed back in as an order.
Route::get('/report/order-form.pdf', [OrderController::class, 'orderFormPdf'])
    ->name('report.order-form')
    ->middleware(['auth', 'permission:manage-orders']);

// Pure render, no mutation (see OrderFillingController::printPickSheets for
// the batch select+lock step that precedes this) — safe to reload/reprint.
Route::get('/report/pick-sheets.pdf', [OrderFillingController::class, 'pickSheetsPdf'])
    ->name('report.pick-sheets')
    ->middleware(['auth', 'permission:manage-orders']);

// BOL (Bill of Lading) for a Filled order. bol_number is assigned on first
// generation and reused thereafter — safe to reload/reprint.
Route::get('/report/bol/{id}.pdf', [OrderController::class, 'bolPdf'])
    ->name('report.bol')
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
    Route::post('/receiving/{id}/photo', [ReceivingController::class, 'uploadPhoto']);
    Route::get('/receiving/{id}/photo', [ReceivingController::class, 'photo']);

    // drivers: a sub-resource of intake (see DriverController), not its own
    // admin page/permission.
    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers', [DriverController::class, 'store']);
    Route::put('/drivers/{driver}', [DriverController::class, 'update']);

    // Recording/editing a donation offer (logging the call) is loose —
    // decision actions are gated separately below.
    Route::get('/donation-offers', [DonationOfferController::class, 'index']);
    Route::get('/donation-offers/unmatched-donations', [DonationOfferController::class, 'unmatchedDonations']);
    Route::post('/donation-offers', [DonationOfferController::class, 'store']);
    Route::put('/donation-offers/{donationOffer}', [DonationOfferController::class, 'update']);
    Route::post('/donation-offers/{donationOffer}/note', [DonationOfferController::class, 'addNote']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-donation-offers']], function () {
    Route::post('/donation-offers/{donationOffer}/approve', [DonationOfferController::class, 'approve']);
    Route::post('/donation-offers/{donationOffer}/refuse', [DonationOfferController::class, 'refuse']);
    Route::post('/donation-offers/{donationOffer}/divert', [DonationOfferController::class, 'divert']);
    Route::post('/donation-offers/{donationOffer}/cancel', [DonationOfferController::class, 'cancel']);
    Route::post('/donation-offers/{donationOffer}/match', [DonationOfferController::class, 'match']);
});

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

// Facility sign-in kiosk (PROJECT_ANALYSIS.md Part 5) — kiosk-access
// (not auth+permission) so these work both for a normally logged-in
// operate-volunteer-kiosk holder AND a guest request from a device in
// kiosk mode (see EnsureKioskAccess). Nothing here depends on Auth::id():
// a kiosk sign-in is the volunteer's own record, not staff data entry.
Route::group(['prefix' => 'json', 'middleware' => ['kiosk-access']], function () {
    Route::get('/volunteer-sign-ins/roster', [VolunteerSignInController::class, 'roster']);
    Route::get('/volunteer-sign-ins/search', [VolunteerSignInController::class, 'search']);
    Route::post('/volunteer-sign-ins/people', [VolunteerSignInController::class, 'quickCreatePerson']);
    Route::post('/volunteer-sign-ins/guests', [VolunteerSignInController::class, 'quickCreateGuest']);
    Route::post('/volunteer-sign-ins', [VolunteerSignInController::class, 'store']);
    Route::post('/volunteer-sign-ins/{volunteerSignIn}/sign-out', [VolunteerSignInController::class, 'signOut']);
    Route::put('/volunteer-sign-ins/{volunteerSignIn}', [VolunteerSignInController::class, 'update']);

    // Read-only, filtered to the requesting device/kiosk's own location —
    // management (store) of this list lives in the admin-system group
    // below, alongside the rest of the Kiosk Settings page.
    Route::get('/sign-in-categories', [SignInCategoryController::class, 'index']);

    // Agency/Task suggestion lists (non-sensitive, no location scoping) —
    // read here so the kiosk device itself can populate its type-ahead;
    // management (store) lives in the admin-system group below.
    Route::get('/kiosk-suggestions', [KioskSuggestionController::class, 'index']);
});

// Enabling kiosk mode itself requires a real logged-in session (you can't
// bootstrap trust from a device that isn't already in kiosk mode) — this
// is the one kiosk-related action that stays auth+permission, not
// kiosk-access.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:operate-volunteer-kiosk']], function () {
    Route::post('/volunteer-kiosk/enable-lock', [KioskModeController::class, 'enable']);
    Route::get('/kiosk-locations/active', [KioskLocationController::class, 'active']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:certify-volunteer-hours']], function () {
    Route::post('/volunteer-sign-ins/certify', [VolunteerSignInController::class, 'certify']);
});

// The notification bell (AuthenticatedLayout.vue) — generic over Laravel's
// own Notifiable trait, currently only fed by KioskCheckInAlert. Just
// `auth`, no extra permission key: recipients are whoever the notification
// was addressed to, scoped naturally via $request->user()->notifications().
Route::group(['prefix' => 'json', 'middleware' => ['auth']], function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

// Building safety (2026-08-23 design pass). kiosk-access rather than plain
// `auth` — closeout/roll-call start/close are additionally PIN-verified
// internally (see BuildingSafetyController), so they work from a locked
// kiosk with nobody logged in, but reaching them at all still requires
// either a normal operate-volunteer-kiosk session or a device already in
// kiosk-lock mode. Was briefly wide open (no middleware at all, so the
// emergency-occupancy-list endpoint below was leaking full names to
// anyone on the internet) until this was caught. None of this depends on
// Auth::id() the way most of the app does.
Route::group(['prefix' => 'json', 'middleware' => ['kiosk-access']], function () {
    Route::get('/building-safety/occupancy-count', [BuildingSafetyController::class, 'kioskOccupancyCount']);
    Route::get('/building-safety/emergency-occupancy-list', [BuildingSafetyController::class, 'emergencyOccupancyList']);
    Route::get('/building-safety/kiosk-operators', [BuildingSafetyController::class, 'kioskOperatorCandidates']);
    Route::post('/building-safety/closeout', [BuildingSafetyController::class, 'closeout']);
    Route::post('/building-safety/roll-calls', [BuildingSafetyController::class, 'startRollCall']);
    Route::post('/building-safety/roll-calls/{buildingRollCall}/close', [BuildingSafetyController::class, 'closeRollCall']);
});

// Viewing occupancy/participating in an active roll call is a normal
// permission (not PIN-gated) — reaches whoever might be checking from
// their phone, not just kiosk operators. See view-building-occupancy in
// PermissionsSeeder.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:view-building-occupancy']], function () {
    Route::get('/building-safety/occupancy', [BuildingSafetyController::class, 'occupancy']);
    Route::get('/building-safety/roll-calls/active', [BuildingSafetyController::class, 'activeRollCall']);
    Route::post('/building-safety/roll-calls/{buildingRollCall}/confirmations/{volunteerSignIn}', [BuildingSafetyController::class, 'confirmPerson']);
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

// Any logged-in user can submit a report or dismiss the current banner —
// this isn't tied to any specific resource.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:general-access']], function () {
    Route::post('/feedback-reports', [FeedbackReportController::class, 'store']);
    Route::post('/banner-dismiss', [BannerSettingController::class, 'dismiss']);
    // Shared by People and Order Entry (manage-people / manage-orders) — gated
    // loosely like the above since it only echoes back a geocoding result,
    // not a resource-specific read/write.
    Route::post('/geocode/county', [GeocodeController::class, 'lookupCounty']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-feedback']], function () {
    Route::get('/feedback-reports', [FeedbackReportController::class, 'index']);
    Route::get('/feedback-reports/{feedbackReport}/screenshot', [FeedbackReportController::class, 'screenshot']);
    Route::patch('/feedback-reports/{feedbackReport}', [FeedbackReportController::class, 'update']);
    Route::put('/banner-settings', [BannerSettingController::class, 'update']);
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
    Route::put('/system/offline-mode', [SystemController::class, 'saveOfflineMode']);

    Route::get('/active-sessions', [ActiveSessionController::class, 'index']);
    Route::get('/login-history', [ActiveSessionController::class, 'history']);
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

// Kiosk Settings page (2026-08-26): behavior settings, locations, guest
// types (sign_in_categories, management side), and agency/task
// suggestions. All system-wide config, same gate as every other
// system-wide toggle.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/kiosk-settings', [KioskSettingController::class, 'show']);
    Route::put('/kiosk-settings', [KioskSettingController::class, 'update']);

    Route::get('/kiosk-locations', [KioskLocationController::class, 'index']);
    Route::post('/kiosk-locations', [KioskLocationController::class, 'store']);
    Route::put('/kiosk-locations/{kioskLocation}', [KioskLocationController::class, 'update']);

    Route::post('/kiosk-suggestions', [KioskSuggestionController::class, 'store']);

    Route::get('/kiosk-locations/{kioskLocation}/sign-in-categories', [SignInCategoryController::class, 'forLocation']);
    Route::post('/sign-in-categories', [SignInCategoryController::class, 'store']);
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
