<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAccountApproved;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\FeedbackAggregationSetting;
use App\Models\KioskLocation;
use App\Models\KioskSetting;
use App\Models\ManagedDevice;
use App\Models\MenuItem;
use App\Services\GitVersionService;
use App\Services\PinLoginService;
use App\Services\TrainingMode;
use App\Services\TurnstileService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

// First-run setup wizard — only reachable until an administrator exists (see
// FirstRunService / RedirectToSetup); afterwards both just redirect to /login.
Route::get('/install', [SetupController::class, 'show'])->name('setup.show');
Route::post('/install', [SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');

// A new warehouse waiting for its setup code (MULTI_WAREHOUSE.md §7). ResolveWarehouse lets an awaiting-setup warehouse answer
// only these; the controller refuses anything once the warehouse is active.
Route::get('/activate', [WarehouseSetupController::class, 'show'])->name('activate.show');
Route::post('/activate/code', [WarehouseSetupController::class, 'code'])->middleware('throttle:30,1')->name('activate.code');
Route::post('/activate/setup', [WarehouseSetupController::class, 'setup'])->middleware('throttle:15,1')->name('activate.setup');

// First-run "restore a backup" path. Named setup.* so RedirectToSetup lets it through; the controller
// itself refuses anything but a never-set-up instance. The status poll skips the session middleware: while
// the database is being replaced there is no sessions table to read (nor a setup_state table to ask).
Route::prefix('install/restore')->name('setup.restore.')->group(function () {
    Route::post('/', [RestoreController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::get('/status', [RestoreController::class, 'status'])->name('status')
        ->withoutMiddleware([
            StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, VerifyCsrfToken::class,
            RedirectToSetup::class, HandleInertiaRequests::class, TrackSessionActivity::class, EnsureAccountApproved::class,
        ]);
    Route::post('/launch', [RestoreController::class, 'launch'])->middleware('throttle:20,1')->name('launch');
    Route::get('/{id}', [RestoreController::class, 'uploadStatus'])->name('upload.status');
    Route::put('/{id}', [RestoreController::class, 'uploadChunk'])->name('upload.chunk');
    Route::post('/{id}/complete', [RestoreController::class, 'complete'])->name('upload.complete');
    Route::delete('/{id}', [RestoreController::class, 'cancel'])->name('upload.cancel');
});

// Web app manifest, for "Add to Home Screen" / install on phones and tablets. A route rather than a static
// file so the installed name follows this instance's APP_NAME. Fetched by the browser without cookies, so
// no session, and it must answer before first-run setup too.
Route::get('/manifest.webmanifest', function () {
    return response()->json([
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#202934',
        'theme_color' => '#ffffff',
        'icons' => [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
})->name('manifest')->withoutMiddleware([
    StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, VerifyCsrfToken::class,
    RedirectToSetup::class, HandleInertiaRequests::class, TrackSessionActivity::class, EnsureAccountApproved::class,
]);

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        // Hidden while Turnstile is on but keyless: the page would only show a setup notice.
        'canRegister' => Route::has('register') && ! app(TurnstileService::class)->misconfigured(),
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

// Where the tab guard (GuardWarehouseTab) sends a tab left on a warehouse the browser no longer uses.
Route::get('/warehouse-switched', fn () => Inertia::render('WarehouseSwitched'))->name('warehouse.switched');

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
    return Inertia::render('DonationOffers', [
        'breadcrumb' => MenuItem::getBreadcrumb('/receiving'),
        // Whether to show the decision buttons at all — the endpoints they
        // call are still independently gated server-side either way, this
        // just avoids offering an action that's already known to 403 (see
        // PROJECT_ANALYSIS.md Part 12).
        'canDecide' => Auth::user()->hasPermission('manage-donation-offers'),
    ]);
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

// This installation's About screen (version, Docker, license): reached from the profile menu only (no MenuItem),
// open to any logged-in user. Distinct from the public /about project story above.
Route::get('/about-this-install', function () {
    $git = app(GitVersionService::class);

    return Inertia::render('AppAbout', [
        'version' => $git->release(),
        'commit' => $git->currentCommit(),
        'docker' => config('system.runtime') === 'docker',
    ]);
})->middleware(['auth']);

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
    return Inertia::render('PalletLocation', [
        'breadcrumb' => MenuItem::getBreadcrumb('/inventory-movement'),
        'canAdjust' => (bool) auth()->user()?->hasPermission('adjust-inventory'),
    ]);
})->middleware(['auth', 'permission:manage-pallets']);

Route::get('/pallet-management', function () {
    return Inertia::render('PalletManagement',
        ['breadcrumb' => MenuItem::getBreadcrumb('/pallet-management')]);
})->middleware(['auth', 'permission:manage-pallets']);

Route::get('/setup/items', function () {
    return Inertia::render('ItemEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/items')]);
})->middleware(['auth', 'permission:manage-items,manage-itemtypes']);

Route::get('/setup/carton-codes', function () {
    return Inertia::render('CartonCodes',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/carton-codes')]);
})->middleware(['auth', 'permission:manage-items']);

Route::get('/setup/people', function () {
    return Inertia::render('People', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/people'),
        // DELETE /json/people/{id} is admin-people; only offer the button to someone it would work for.
        'canDelete' => (bool) auth()->user()?->hasPermission('admin-people'),
    ]);
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

Route::get('/setup/locations/pick-path', function () {
    return Inertia::render('PickPath',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/locations/pick-path')]);
})->middleware(['auth', 'permission:manage-locations']);

Route::get('/setup/locations', function () {
    return Inertia::render('LocationEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/locations')]);
})->middleware(['auth', 'permission:manage-locations']);

// TODO.md item 5: admin UI for the units table (/json/units already
// existed, gated the same way, with no page bound to it).
Route::get('/setup/units', function () {
    return Inertia::render('Units',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/units')]);
})->middleware(['auth', 'permission:manage-units']);

// Feedback #43: admin UI for the packagetypes table (/json/packagetypes
// already existed, gated the same way, with no page bound to it — see
// PackageTypeController's fixed validation rules for the underlying bug).
Route::get('/setup/package-types', function () {
    return Inertia::render('PackageTypes',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/package-types')]);
})->middleware(['auth', 'permission:manage-packagetypes']);

// Updates & Backups: one page for software updates, offline mode, backup zips, the tiered backup schedule and
// the app key. Each section is gated by its own permission (the JSON endpoints below enforce the same), so the
// page opens for anyone holding admin-system or manage-backups.
Route::get('/setup/system', function () {
    $user = Auth::user();
    $canAdmin = $user->hasPermission('admin-system');
    $canBackups = $user->hasPermission('manage-backups');
    abort_unless($canAdmin || $canBackups, 403, 'Missing required permission: admin-system');

    return Inertia::render('SystemAdmin', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/system'),
        'runtime' => config('system.runtime') === 'docker' ? 'docker' : 'systemd',
        'canAdminSystem' => $canAdmin,
        'canManageBackups' => $canBackups,
        'canViewAppKey' => $user->hasPermission('view-app-key'),
    ]);
})->middleware(['auth']);

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
// Trainee self sign-up (TraineeSignupController): public, open only while Training Mode is on. A whole room signs
// up at once from one Wi-Fi network (one IP), so the limit (80/min) covers a room of ~55; Turnstile keeps bots out.
Route::get('/join', [TraineeSignupController::class, 'show'])->name('join');
Route::post('/join', [TraineeSignupController::class, 'store'])->middleware('throttle:80,1');

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

Route::get('/forms/{slug}', [PublicFormController::class, 'show'])->name('forms.show');
Route::post('/forms/{slug}', [PublicFormController::class, 'submit'])->name('forms.submit');

// Help pages: static how-to guides, one per data-entry page, reached from that page's "Help: ..."
// text link (Components/HelpLink.vue) or from the main-menu Help tile's /help list.
// Visible to anyone authenticated (no permission gate).
Route::get('/help', function () {
    return Inertia::render('Help/Index', [
        'breadcrumb' => MenuItem::getBreadcrumb('/help'),
        'guides' => collect(HelpReportController::GUIDES)
            ->map(fn ($title, $slug) => ['slug' => $slug, 'title' => $title])->values(),
        // Training Mode's team activity sheets (TrainingAdminController::teamSheetsBuilder).
        'trainingTeams' => TrainingMode::TEAMS,
    ]);
})->middleware(['auth']);

Route::get('/help/{guide}', function (string $guide) {
    return Inertia::render('Help/'.Str::studly($guide), ['breadcrumb' => [
        ...MenuItem::getBreadcrumb('/help'),
        ['href' => '/help/'.$guide, 'title' => HelpReportController::GUIDES[$guide]],
    ]]);
})->whereIn('guide', array_keys(HelpReportController::GUIDES))->middleware(['auth']);

// Signs (SignController): ready-made or typed warehouse signs on Letter paper, on the Setup menu.
// Anyone signed in, like the Help guides. /setup/signs/preview is the PDF's own view as HTML.
Route::get('/setup/signs', [SignController::class, 'page'])->middleware(['auth']);
Route::get('/setup/signs/preview', [SignController::class, 'preview'])->middleware(['auth']);
Route::get('/setup/signs/fonts/{file}', [SignController::class, 'font'])
    ->whereIn('file', ['LiberationSans-Bold.ttf', 'LiberationSans-Regular.ttf'])->middleware(['auth']);
Route::get('/report/sign.pdf', [SignController::class, 'pdf'])->name('report.sign')->middleware(['auth']);

// Training Mode (App\Services\TrainingMode). Setup > Training Mode is manage-training; switching the mode on/off
// is admin-system. The team activity sheets print from /help, for anyone signed in, like the help guides.
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
Route::put('/json/training/mode', [TrainingAdminController::class, 'setMode'])
    ->middleware(['auth', 'permission:admin-system']);
Route::post('/json/training/reset', [TrainingAdminController::class, 'reset'])
    ->middleware(['auth', 'permission:admin-system']);
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

// Every guide in one printable PDF (the /help list's "Print all guides").
Route::get('/report/help/all', [HelpReportController::class, 'showAll'])
    ->name('report.help.all')
    ->middleware(['auth']);

// Printable PDF version of a help guide — same no-permission-gate rule as
// the guide pages themselves. One route for every guide listed in
// HelpReportController::GUIDES.
Route::get('/report/help/{guide}',
    [HelpReportController::class, 'show'])
    ->whereIn('guide', array_keys(HelpReportController::GUIDES))
    ->name('report.help')
    ->middleware(['auth']);

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

// Inventory labels (one per item set put on a pallet), by pallet_stock_assignments id — the Inventory Movement screen's
// Fill Pallet, same gate as its endpoints.
Route::get('/report/inventory-labels',
    [InventoryLabelController::class, 'download'])
    ->name('report.inventory-labels')
    ->middleware(['auth', 'permission:manage-pallets']);

// Pre-print Labels batch (PROJECT_ANALYSIS.md Part 12) — an arbitrary set
// of just-created, not-yet-assigned pallet ids, same gate as the rest of
// Receiving's label printing.
Route::get('/report/pallets/preprint',
    [PalletReportController::class, 'generatePreprintLabels'])
    ->name('report.pallets.preprint')
    ->middleware(['auth', 'permission:manage-receiving']);

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

// Browser handoff for a paired R Station (see DeviceSessionController): trades a single-use ticket for
// the device-session cookie, then lands on the device's Function page. Unauthenticated by design.
Route::get('/device-session/{ticket}', [DeviceSessionController::class, 'start'])->middleware('throttle:30,1');

// Chrome-free, auto-refreshing stats screens for an always-on R Station display, shown with nobody
// logged in (display-access = the matching permission OR a device session with that Function).
Route::get('/display/sitrep', function () {
    return Inertia::render('Display/Stats', ['mode' => 'sitrep']);
})->middleware('display-access:view-sitrep,status_sitrep');
Route::get('/display/dashboard', function () {
    return Inertia::render('Display/Stats', ['mode' => 'dashboard']);
})->middleware('display-access:view-dashboard,status_dashboard');
Route::group(['prefix' => 'json/display'], function () {
    Route::get('/sitrep', [SitrepController::class, 'index'])->middleware('display-access:view-sitrep,status_sitrep');
    Route::get('/dashboard', [DashboardController::class, 'display'])->middleware('display-access:view-dashboard,status_dashboard');
});

// External Situation Report: restricted subset of the same data, meant to
// be shared outside the organization (FEMA/state liaisons) — see
// SitrepController for what's deliberately left out.
Route::get('/reports/sitrep', function () {
    return Inertia::render('SituationReport',
        ['breadcrumb' => MenuItem::getBreadcrumb('/reports/sitrep')]);
})->middleware(['auth', 'permission:view-sitrep']);

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
    Route::post('/users/bulk', [UserAdminController::class, 'bulk']);
    Route::put('/users/{id}', [UserAdminController::class, 'update']);
    Route::post('/users/{id}/deactivate', [UserAdminController::class, 'deactivate']);
    Route::post('/users/{id}/reactivate', [UserAdminController::class, 'reactivate']);
    Route::post('/users/{id}/resend-invite', [UserAdminController::class, 'resendInvite']);
    Route::post('/users/{id}/approve', [UserAdminController::class, 'approve']);
    Route::post('/users/{id}/set-password', [UserAdminController::class, 'setPassword']);
    Route::post('/users/{id}/require-password-change', [UserAdminController::class, 'requirePasswordChange']);
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
    Route::put('/order-filling/allocations', [OrderFillingController::class, 'approveAllocation']);
    Route::patch('/order-filling/{id}/complete', [OrderFillingController::class, 'completeFilling']);
    Route::get('/order-filling/{id}/plan', [OrderFillingController::class, 'plan'])->whereNumber('id');
    Route::post('/order-filling/pallets/{id}/open', [OrderFillingController::class, 'openPallet'])->whereNumber('id');
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

// Re-pointing an item at another ACS # rewrites how all of its history reads, so it is Administrator-only.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/items/{id}/move-impact', [ItemController::class, 'moveImpact'])->whereNumber('id');
    Route::post('/items/{id}/move', [ItemController::class, 'move'])->whereNumber('id');
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-items']], function () {
    Route::get('/items', [ItemController::class, 'index']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::put('/items/{id}', [ItemController::class, 'update']);
    Route::delete('/items/{id}', [ItemController::class, 'destroy']);
    Route::get('/carton-codes', [UpcController::class, 'indexCartons']);
    Route::delete('/carton-codes/{id}', [UpcController::class, 'destroyCarton'])->whereNumber('id');
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-units']], function () {
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);
    Route::put('/units/{id}', [UnitController::class, 'update']);
    Route::delete('/units/{id}', [UnitController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-categories']], function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-locations']], function () {
    Route::get('/locations', [LocationController::class, 'index']);
    Route::put('/locations/order', [LocationController::class, 'reorder']);
    Route::post('/locations', [LocationController::class, 'store']);
    Route::put('/locations/{id}', [LocationController::class, 'update']);
    Route::delete('/locations/{id}', [LocationController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-warehouses']], function () {
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::post('/warehouses', [WarehouseController::class, 'store']);
    Route::put('/warehouses/{id}', [WarehouseController::class, 'update']);
    Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-uses']], function () {
    Route::get('/uses', [UseController::class, 'index']);
    Route::post('/uses', [UseController::class, 'store']);
    Route::put('/uses/{id}', [UseController::class, 'update']);
    Route::delete('/uses/{id}', [UseController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-pallets']], function () {
    // Pallet Status (history/audit trail for pallets)
    Route::get('/palletstatus', [PalletStatusController::class, 'index']);
    Route::get('/palletstatus/summary', [PalletStatusController::class, 'summary']);
    Route::get('/palletstatus/location/{location}', [PalletStatusController::class, 'locationPallets']);
    Route::get('/palletstatus/lookup', [PalletStatusController::class, 'lookup']);
    Route::get('/palletstatus/statuses', [PalletStatusController::class, 'statuses']);
    Route::post('/palletstatus', [PalletStatusController::class, 'store']);
    Route::put('/palletstatus/{id}', [PalletStatusController::class, 'update']);
    Route::delete('/palletstatus/{id}', [PalletStatusController::class, 'destroy']);

    // Pallets (five-kind model: Receiving/Warehouse/Shipping/Hold/Quarantine)
    Route::get('/pallets/{id}/contents', [PalletStockController::class, 'contents'])->whereNumber('id');
    Route::post('/pallets/{id}/contents/adjust', [PalletStockController::class, 'adjust'])->whereNumber('id');
    Route::get('/pallet-stock/staging', [PalletStockController::class, 'staging']);
    Route::post('/pallet-stock/fill', [PalletStockController::class, 'fill']);
    Route::post('/pallet-stock/{id}/open', [PalletStockController::class, 'open'])->whereNumber('id');
    Route::post('/pallet-stock/{id}/seal', [PalletStockController::class, 'seal'])->whereNumber('id');
    Route::get('/pallets', [PalletController::class, 'index']);
    Route::get('/pallets/{kind}', [PalletController::class, 'index']);
    Route::get('/pallets/{kind}/{status}', [PalletController::class, 'index']);
    Route::post('/pallets', [PalletController::class, 'store']);
    Route::put('/pallets/{id}', [PalletController::class, 'update']);
    Route::delete('/pallets/{id}', [PalletController::class, 'destroy']);
});

// Manual inventory adjustments of a pallet's contents are their own permission, beyond ordinary stock movements.
Route::post('/json/pallet-stock/{id}/adjust-inventory', [PalletStockController::class, 'adjustInventory'])
    ->whereNumber('id')->middleware(['auth', 'permission:adjust-inventory']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-itemtypes']], function () {
    Route::get('/itemtypes', [ItemTypeController::class, 'index']);
    Route::get('/itemtypes/{mod}', [ItemTypeController::class, 'index']);
    Route::post('/itemtypes', [ItemTypeController::class, 'store']);
    Route::put('/itemtypes/{id}', [ItemTypeController::class, 'update']);
    Route::delete('/itemtypes/{id}', [ItemTypeController::class, 'destroy']);
});

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

// 4 x 6.5 in shipping label for a Filled order (pure render, no mutation).
Route::get('/report/shipping-label/{id}.pdf', [OrderController::class, 'shippingLabelPdf'])
    ->name('report.shipping-label')
    ->middleware(['auth', 'permission:manage-orders']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-packagetypes']], function () {
    Route::get('/packagetypes', [PackageTypeController::class, 'index']);
    Route::post('/packagetypes', [PackageTypeController::class, 'store']);
    Route::put('/packagetypes/{id}', [PackageTypeController::class, 'update']);
    Route::delete('/packagetypes/{id}', [PackageTypeController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-sorting']], function () {
    // Sorting sessions (scan-driven donation sorting; lines autosave one at a time)
    Route::get('/sorting-sessions', [SortingSessionController::class, 'index']);
    Route::post('/sorting-sessions', [SortingSessionController::class, 'store']);
    // Barcode scan -> item (our own items.upc first; an online suggestion only on a miss)
    Route::get('/upc/resolve', [UpcController::class, 'resolve']);
    Route::get('/upc/suggest', [UpcController::class, 'suggest']);
    Route::post('/upc/cartons', [UpcController::class, 'storeCarton']);
    Route::get('/sorting-sessions/pallet/{tag}', [SortingSessionController::class, 'pallet']);
    Route::post('/sorting-sessions/pallet/{tag}/empty', [SortingSessionController::class, 'palletEmpty']);
    Route::get('/sorting-sessions/{id}', [SortingSessionController::class, 'show']);
    Route::patch('/sorting-sessions/{id}', [SortingSessionController::class, 'update']);
    Route::post('/sorting-sessions/{id}/consolidate', [SortingSessionController::class, 'consolidate']);
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
    // Pre-print Labels (PROJECT_ANALYSIS.md Part 12, replaces the deleted
    // /reports/labels page): print a batch of unassigned R labels ahead of
    // a donation record existing, then attach the ones actually used to a
    // real intake once it's entered — an alternate label-first path
    // alongside createPallets' existing enter-then-print flow.
    Route::post('/receiving/preprint-labels', [ReceivingController::class, 'preprintLabels']);
    Route::post('/receiving/{id}/attach-pallets', [ReceivingController::class, 'attachPreprintedPallets']);
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
    Route::post('/container-types', [ContainerTypeController::class, 'store']);
    Route::put('/container-types/{id}', [ContainerTypeController::class, 'update']);
    Route::delete('/container-types/{id}', [ContainerTypeController::class, 'destroy']);
});

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-streams']], function () {
    Route::get('/streams', [StreamController::class, 'index']);
    Route::post('/streams', [StreamController::class, 'store']);
    Route::put('/streams/{id}', [StreamController::class, 'update']);
    Route::delete('/streams/{id}', [StreamController::class, 'destroy']);
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

// Any logged-in user can submit a report or dismiss the current banner —
// this isn't tied to any specific resource.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:general-access']], function () {
    // 10/minute — generous for a legitimate user submitting a real report,
    // enough to blunt automated probing/spam of the submission endpoint.
    Route::post('/feedback-reports', [FeedbackReportController::class, 'store'])
        ->middleware('throttle:10,1');
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

// Deleting a person is a records-integrity issue, not a permissions tier —
// no bundle grants this by default; kept separate from manage-people
// pending the Partner/Donor rework, which is expected to replace this with
// deactivate/hide rather than a hard delete (see PROJECT_ANALYSIS.md Part 12).
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-people']], function () {
    Route::delete('/people/{id}', [PeopleController::class, 'destroy']);
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
    Route::get('/backups/{id}/download', [BackupController::class, 'download'])->whereNumber('id');
    Route::get('/backups/tier/{tier}/{stamp}/download', [BackupController::class, 'downloadTier']);
    Route::delete('/backups/{id}', [BackupController::class, 'destroy']);
});
Route::post('/json/backups/app-key', [BackupController::class, 'appKey'])
    ->middleware(['auth', 'permission:view-app-key', 'throttle:5,1']);

// PIN-login global settings (on/off, trust mode) — system-wide config,
// same gate as every other system-wide toggle.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/pin-login-settings', [PinLoginSettingsController::class, 'show']);
    Route::put('/pin-login-settings', [PinLoginSettingsController::class, 'update']);
});

// Email Settings (/setup/email) — system-wide config, same gate as the page.
Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:admin-system']], function () {
    Route::get('/mail-settings', [MailSettingsController::class, 'show']);
    Route::put('/mail-settings', [MailSettingsController::class, 'update']);
    Route::post('/mail-settings/test', [MailSettingsController::class, 'test'])->middleware('throttle:6,1');
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

// Federation: the public half of this instance's identity (peers pin and domain-verify against it).
Route::get('/.well-known/ri-instance.json', [FederationController::class, 'identity'])->middleware('throttle:60,1');

Route::get('/setup/trust', function () {
    return Inertia::render('TrustConsole', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/trust'),
    ]);
})->middleware(['auth', 'permission:manage-trusts']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-trusts']], function () {
    Route::get('/trusts', [TrustConsoleController::class, 'index']);
    Route::post('/trusts/pairing-codes', [TrustConsoleController::class, 'createPairingCode']);
    Route::put('/trusts/identity', [TrustConsoleController::class, 'updateIdentity']);
    Route::post('/trusts/identity/regenerate', [TrustConsoleController::class, 'regenerateIdentity']);
    Route::post('/trusts/invitations', [TrustConsoleController::class, 'createInvitation']);
    Route::post('/trusts/accept-invitation', [TrustConsoleController::class, 'acceptInvitation']);
    Route::post('/trusts/{trust}/verify-domain', [TrustConsoleController::class, 'verifyDomain']);
    Route::post('/trusts/{trust}/refresh-grants', [TrustConsoleController::class, 'refreshGrants']);
    Route::put('/trusts/{trust}/grants', [TrustConsoleController::class, 'setGrants']);
    Route::post('/trusts/{trust}/revoke', [TrustConsoleController::class, 'revoke']);
});

// R Station device fleet (DEVICE_CONTRACT.md): the admin page and
// its JSON. The device-facing endpoints are in routes/api.php.
Route::get('/setup/devices', function () {
    return Inertia::render('RStationDevices', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/devices'),
        'canManageFirmware' => (bool) Auth::user()->hasPermission('manage-firmware'),
    ]);
})->middleware(['auth', 'permission:manage-devices']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-devices']], function () {
    Route::get('/managed-devices', [ManagedDeviceController::class, 'index']);
    Route::put('/managed-devices/{managedDevice}', [ManagedDeviceController::class, 'update']);
    Route::post('/managed-devices/{managedDevice}/revoke', [ManagedDeviceController::class, 'revoke']);
    Route::delete('/managed-devices/{managedDevice}', [ManagedDeviceController::class, 'destroy']);
    Route::post('/device-pairing-codes', [ManagedDeviceController::class, 'createPairingCode']);

    // Images and the print client: for anyone who can pair a device (uploaded under manage-firmware).
    Route::get('/device-downloads', [DeviceDownloadController::class, 'index']);
    Route::get('/device-downloads/{deviceRelease}/{filename}', [DeviceDownloadController::class, 'download']);

    // Typing the print queues a print server reports (see PrinterAdminController).
    Route::get('/printer-overview', [PrinterAdminController::class, 'overview']);
    Route::post('/printers/reorder', [PrinterAdminController::class, 'reorder']);
    Route::put('/printers/label-settings', [PrinterAdminController::class, 'saveLabelSettings']);
    Route::put('/printers/{printer}', [PrinterAdminController::class, 'update']);
    Route::delete('/printers/{printer}', [PrinterAdminController::class, 'destroy']);
});

// All printers at a glance: drag-to-order and live status. Clearing a queue from here is the
// existing manage-print-queue action (POST /json/print-jobs/clear).
Route::get('/setup/printers', function () {
    return Inertia::render('PrinterManagement', [
        'breadcrumb' => MenuItem::getBreadcrumb('/setup/printers'),
        'canClearQueue' => (bool) Auth::user()->hasPermission('manage-print-queue'),
        // Uploading a PDF to print is the same permission as clearing/diverting the queue.
        'canUploadPdf' => (bool) Auth::user()->hasPermission('manage-print-queue'),
    ]);
})->middleware(['auth', 'permission:manage-devices']);

// Firmware (the app/OS release catalogue and where devices look for it) is its own permission,
// narrower than manage-devices: only fleet admins should be able to publish a release.
Route::get('/setup/firmware', function () {
    return Inertia::render('RStationFirmware',
        ['breadcrumb' => MenuItem::getBreadcrumb('/setup/firmware')]);
})->middleware(['auth', 'permission:manage-firmware']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-firmware']], function () {
    Route::get('/firmware-source', [DeviceReleaseController::class, 'source']);
    Route::put('/firmware-source', [DeviceReleaseController::class, 'updateSource']);
    Route::get('/device-releases', [DeviceReleaseController::class, 'index']);
    Route::post('/device-releases', [DeviceReleaseController::class, 'store']);
    // Chunked upload for large files (see DeviceReleaseController).
    Route::post('/device-releases/uploads', [DeviceReleaseController::class, 'startUpload']);
    Route::get('/device-releases/uploads/{id}', [DeviceReleaseController::class, 'uploadStatus']);
    Route::put('/device-releases/uploads/{id}', [DeviceReleaseController::class, 'uploadChunk']);
    Route::post('/device-releases/uploads/{id}/complete', [DeviceReleaseController::class, 'finishUpload']);
    Route::delete('/device-releases/uploads/{id}', [DeviceReleaseController::class, 'cancelUpload']);
    Route::post('/device-releases/{deviceRelease}/tag', [DeviceReleaseController::class, 'tag']);
    Route::post('/device-releases/{deviceRelease}/untag', [DeviceReleaseController::class, 'untag']);
    Route::delete('/device-releases/{deviceRelease}', [DeviceReleaseController::class, 'destroy']);
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

require __DIR__.'/auth.php';
