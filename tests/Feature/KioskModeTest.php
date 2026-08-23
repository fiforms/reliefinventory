<?php

// Locally-named helpers (kiosk*) deliberately not sharing PinLoginTest.php's
// names — those are file-local by that file's own choice (only
// userWithPermissions in Pest.php is meant for cross-file reuse), and this
// file needs to run standalone too.

use App\Models\DeviceTrustGrant;
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
    $user = userWithPermissions('operate-volunteer-kiosk');
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
        ->assertOk();

    expect($device->fresh()->kiosk_mode)->toBeFalse();
});

test('a person with only operate-volunteer-kiosk can still enable kiosk mode', function () {
    kioskEnablePinLogin();
    $device = kioskApprovedDevice();
    $user = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Security']);
    $permission = Permission::firstOrCreate(['key' => 'operate-volunteer-kiosk'], ['name' => 'operate-volunteer-kiosk']);
    $user->person_permissions()->attach($permission->id, ['granted' => true]);

    kioskWithDeviceCookie($device)->actingAs($user)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertOk();
});
