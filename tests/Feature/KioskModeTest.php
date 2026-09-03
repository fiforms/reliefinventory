<?php

// Locally-named helpers (kiosk*) deliberately not sharing PinLoginTest.php's
// names — those are file-local by that file's own choice (only
// userWithPermissions in Pest.php is meant for cross-file reuse), and this
// file needs to run standalone too.

use App\Models\DeviceTrustGrant;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Person;
use App\Models\PinLoginSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function kioskEnablePinLogin(): PinLoginSetting
{
    $settings = PinLoginSetting::current();
    $settings->update(['enabled' => true]);

    return $settings->fresh();
}

function kioskApprovedDevice(): TrustedDevice
{
    return TrustedDevice::create([
        'device_token' => 'kiosk-test-device-'.uniqid(),
        'status' => 'approved',
        'requested_at' => now(),
        'approved_at' => now(),
    ]);
}

function kioskWithDeviceCookie(TrustedDevice $device)
{
    return test()->withCredentials()->withCookie('pin_device_token', $device->device_token);
}

function kioskOperatorUser(): User
{
    $user = userWithPermissions('operate-kiosk');
    $user->pin_hash = Hash::make('13579');
    $user->save();

    return $user;
}

test('enabling kiosk mode requires the permission, an approved device, and PIN login enabled', function () {
    $operator = kioskOperatorUser();
    $device = kioskApprovedDevice();

    // PIN login not enabled yet.
    kioskWithDeviceCookie($device)->actingAs($operator)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertStatus(422);
    expect($device->fresh()->kiosk_mode)->toBeFalse();

    kioskEnablePinLogin();

    kioskWithDeviceCookie($device)->actingAs($operator)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertOk();

    expect($device->fresh()->kiosk_mode)->toBeTrue()
        ->and($device->fresh()->kiosk_mode_enabled_by_person_id)->toBe($operator->id);
});

test('enabling kiosk mode logs the operator out', function () {
    kioskEnablePinLogin();
    $operator = kioskOperatorUser();
    $device = kioskApprovedDevice();

    kioskWithDeviceCookie($device)->actingAs($operator)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertOk();

    kioskWithDeviceCookie($device)->get('/dashboard')->assertRedirect('/login');
});

test('a guest request from a kiosk-mode device can reach the kiosk endpoints with no login', function () {
    kioskEnablePinLogin();
    $device = kioskApprovedDevice();
    $device->update(['kiosk_mode' => true, 'kiosk_mode_enabled_at' => now()]);

    kioskWithDeviceCookie($device)->getJson('/json/volunteer-sign-ins/roster')->assertOk();
});

test('a guest request from a device NOT in kiosk mode is rejected', function () {
    $device = kioskApprovedDevice(); // approved, but kiosk_mode false

    kioskWithDeviceCookie($device)->getJson('/json/volunteer-sign-ins/roster')->assertStatus(403);

    test()->withCredentials()->getJson('/json/volunteer-sign-ins/roster')->assertStatus(403); // no cookie at all
});

test('a successful password login on a kiosk-mode device clears the flag', function () {
    kioskEnablePinLogin();
    $device = kioskApprovedDevice();
    $device->update(['kiosk_mode' => true, 'kiosk_mode_enabled_at' => now()]);
    $user = User::factory()->create(['password' => Hash::make('password')]);

    kioskWithDeviceCookie($device)->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($device->fresh()->kiosk_mode)->toBeFalse();
});

test('a password login that clears kiosk mode lands back on the kiosk page with the closeout prompt', function () {
    kioskEnablePinLogin();
    $device = kioskApprovedDevice();
    $device->update(['kiosk_mode' => true, 'kiosk_mode_enabled_at' => now()]);
    $user = User::factory()->create(['password' => Hash::make('password')]);

    kioskWithDeviceCookie($device)->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/volunteers/kiosk?closeout=1');
});

test('a password login on a device NOT in kiosk mode redirects normally, not to the kiosk page', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    test()->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
});

test('a successful PIN unlock on a kiosk-mode device clears the flag', function () {
    $settings = kioskEnablePinLogin();
    $device = kioskApprovedDevice();
    $device->update(['kiosk_mode' => true, 'kiosk_mode_enabled_at' => now()]);
    $user = User::factory()->create();
    $person = Person::find($user->id);
    $person->pin_hash = Hash::make('24680');
    $person->save();
    DeviceTrustGrant::create([
        'trusted_device_id' => $device->id,
        'person_id' => $person->id,
        'granted_at' => now(),
        'expires_at' => $settings->computeExpiry(now()),
    ]);

    kioskWithDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $person->id, 'pin' => '24680'])
        ->assertOk()
        ->assertJson(['redirect' => '/volunteers/kiosk?closeout=1']);

    expect($device->fresh()->kiosk_mode)->toBeFalse();
});

test('the kiosk page loads for an authenticated user without error', function () {
    kioskEnablePinLogin();
    $user = kioskOperatorUser();

    test()->actingAs($user)->get('/volunteers/kiosk')->assertOk();
});

test('getBreadcrumb still matches a menu item whose link_url happens to carry a query string', function () {
    // Regression guard: MenuItem::getBreadcrumb() previously matched
    // link_url exactly, so a menu item whose link_url carried a query
    // string couldn't be found from the plain path a route passes in, and
    // the page 500'd trying to read a property off a null result. The Setup
    // tile itself no longer carries one (enable-confirmation moved to
    // Dashboard.vue, see KioskEnableConfirmModal), but the lookup should
    // stay tolerant of one regardless.
    $menuItem = MenuItem::where('link_url', '/volunteers/kiosk')->firstOrFail();
    $menuItem->update(['link_url' => '/volunteers/kiosk?foo=1']);

    $breadcrumb = MenuItem::getBreadcrumb('/volunteers/kiosk');

    expect(collect($breadcrumb)->last())->toBe(['href' => '/volunteers/kiosk', 'title' => 'Sign-in Kiosk']);
});

test('a person with only operate-kiosk can still enable kiosk mode', function () {
    kioskEnablePinLogin();
    $device = kioskApprovedDevice();
    $user = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Security']);
    $permission = Permission::firstOrCreate(['key' => 'operate-kiosk'], ['name' => 'operate-kiosk']);
    $user->person_permissions()->attach($permission->id, ['granted' => true]);

    kioskWithDeviceCookie($device)->actingAs($user)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertOk();
});
