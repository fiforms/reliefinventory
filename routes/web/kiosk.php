<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskLocation;
use App\Models\KioskSetting;
use App\Models\ManagedDevice;
use App\Models\MenuItem;
use App\Services\PinLoginService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Kiosk, building safety and PIN login
 *
 * The volunteer/guest sign-in kiosk, building safety (occupancy and roll calls), the Kiosk Settings page, PIN login settings and
 * trusted devices. Design rationale: dev/FACILITY_NETWORK.md.
 *
 * Access: the kiosk endpoints use `kiosk-access` (see EnsureKioskAccess), NOT `auth`, so a locked kiosk with nobody signed in can
 * use them. Everything else is `auth` plus a permission (`operate-kiosk`, `manage-kiosk`, `manage-trusted-devices`, ...). Check
 * which kind a route is before copying one.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

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
    // A paired R Station's location is its pairing's (device session — DeviceSessionController).
    $location = ManagedDevice::fromRequest(request())?->location ?: $device?->kioskLocation ?: ($activeLocations->count() === 1 ? $activeLocations->first() : null);

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

Route::get('/setup/kiosk-settings', function () {
    return Inertia::render('KioskSettings',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/kiosk-settings')]);
})->middleware(['auth', 'permission:manage-kiosk']);

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

// PIN Login on This Device (profile menu): any signed-in user asks for PIN login on the device they're on; an
// approver (manage-trusted-devices) approves it at once. See ThisDeviceController.
Route::get('/this-device', function () {
    return Inertia::render('ThisDevice', ['breadcrumb' => [['href' => '/this-device', 'title' => 'PIN Login on This Device']]]);
})->middleware(['auth', 'permission:general-access']);
Route::middleware(['auth', 'permission:general-access'])->group(function () {
    Route::get('/json/this-device', [ThisDeviceController::class, 'show']);
    Route::post('/json/this-device', [ThisDeviceController::class, 'store']);
});

// Facility sign-in kiosk (rationale: dev/FACILITY_NETWORK.md) — kiosk-access
// (not auth+permission) so these work both for a normally logged-in
// operate-kiosk holder AND a guest request from a device in
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
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:operate-kiosk']], function () {
    Route::post('/volunteer-kiosk/enable-lock', [KioskModeController::class, 'enable']);
    Route::get('/kiosk-locations/active', [KioskLocationController::class, 'active']);

    // Confirming the building empty is only ever reachable after a real
    // staff login (VolunteerKiosk.vue's "Confirm Building Empty" only
    // appears once isAuthenticated) — there's no locked/guest path that
    // reaches it, so it stays plain auth+permission rather than the
    // PIN-verified kiosk-access group below (unlike roll-call start/close,
    // which do need to work from a locked kiosk with nobody logged in).
    Route::post('/building-safety/closeout', [BuildingSafetyController::class, 'closeout']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:certify-volunteer-hours']], function () {
    Route::post('/volunteer-sign-ins/certify', [VolunteerSignInController::class, 'certify']);
});

// Building safety (2026-08-23 design pass). kiosk-access rather than plain
// `auth` — roll-call start/close are additionally PIN-verified internally
// (see BuildingSafetyController), so they work from a locked kiosk with
// nobody logged in, but reaching them at all still requires either a
// normal operate-kiosk session or a device already in
// kiosk-lock mode. Was briefly wide open (no middleware at all, so the
// emergency-occupancy-list endpoint below was leaking full names to
// anyone on the internet) until this was caught. None of this depends on
// Auth::id() the way most of the app does. closeout() moved out of this
// group (see the auth+permission group above) — it's never actually
// reached from a locked/guest kiosk today, so the PIN step it used to
// have was pure redundancy on top of an already-authenticated session.
Route::group(['prefix' => 'json', 'middleware' => ['kiosk-access']], function () {
    Route::get('/building-safety/occupancy-count', [BuildingSafetyController::class, 'kioskOccupancyCount']);
    Route::get('/building-safety/emergency-occupancy-list', [BuildingSafetyController::class, 'emergencyOccupancyList']);
    Route::get('/building-safety/kiosk-operators', [BuildingSafetyController::class, 'kioskOperatorCandidates']);
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

// PIN-login global settings (on/off, trust mode) — system-wide config,
// same gate as every other system-wide toggle.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/pin-login-settings', [PinLoginSettingsController::class, 'show']);
    Route::put('/pin-login-settings', [PinLoginSettingsController::class, 'update']);
});

// Kiosk Settings page (2026-08-26): behavior settings, locations, guest
// types (sign_in_categories, management side), and agency/task
// suggestions. Individually delegable via manage-kiosk (split from
// admin-system 2026-09-02 — see permissions-tied-to-function-not-identity
// memory) rather than every other system-wide toggle's admin-system gate.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-kiosk']], function () {
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
    Route::post('/trusted-devices/{id}/deny', [TrustedDeviceController::class, 'deny']);
    Route::put('/trusted-devices/{id}', [TrustedDeviceController::class, 'relabel']);
});
