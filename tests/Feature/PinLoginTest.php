<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\DeviceTrustGrant;
use App\Models\PinLoginSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function enablePinLogin(array $overrides = []): PinLoginSetting
{
    $settings = PinLoginSetting::current();
    $settings->update(array_merge(['enabled' => true], $overrides));

    return $settings->fresh();
}

function approvedDevice(): TrustedDevice
{
    return TrustedDevice::create([
        'device_token' => 'test-device-'.uniqid(),
        'status' => 'approved',
        'requested_at' => now(),
        'approved_at' => now(),
    ]);
}

function withDeviceCookie(TrustedDevice $device)
{
    // postJson/getJson don't send cookies at all unless withCredentials()
    // is set (unlike get()/post(), which always do). Cookie encryption
    // must stay ON (the default) here, not disabled: EncryptCookies still
    // runs server-side regardless of any test-client setting and silently
    // nulls out any cookie it can't decrypt — disableCookieEncryption()
    // only controls how the *test client* prepares the outgoing cookie, so
    // pairing it with a live EncryptCookies middleware is exactly backwards.
    return test()->withCredentials()->withCookie('pin_device_token', $device->device_token);
}

function userWithPin(string $pin = '13579'): User
{
    $user = User::factory()->create();
    $user->pin_hash = Hash::make($pin);
    $user->save();

    return $user;
}

// ------------------------------------------------------------- feature off/on

test('the login page does not redirect to unlock when the feature is disabled', function () {
    $response = $this->get('/login');

    $response->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

test('the login page redirects to unlock when the feature is enabled', function () {
    enablePinLogin();

    $response = $this->get('/login');

    $response->assertRedirect(route('unlock'));
});

test('the login page email bypass avoids redirecting back to unlock', function () {
    enablePinLogin();

    $response = $this->get('/login?email=1');

    $response->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

// ------------------------------------------------------------- device gating

test('an unrecognized device sees no trusted people, even with the feature enabled', function () {
    enablePinLogin();

    $response = $this->get('/unlock');

    $response->assertInertia(fn ($page) => $page
        ->component('Auth/Unlock')
        ->where('deviceApproved', false)
        ->where('people', []));
});

test('visiting unlock on a new device creates a pending TrustedDevice but grants nothing', function () {
    enablePinLogin();

    expect(TrustedDevice::count())->toBe(0);
    $this->get('/unlock');

    expect(TrustedDevice::count())->toBe(1)
        ->and(TrustedDevice::first()->status)->toBe('pending');
});

test('a real login does not grant device trust when the device is not yet approved', function () {
    enablePinLogin();
    $user = User::factory()->create();
    $device = TrustedDevice::create([
        'device_token' => 'pending-device', 'status' => 'pending', 'requested_at' => now(),
    ]);

    withDeviceCookie($device)->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(DeviceTrustGrant::count())->toBe(0);
});

test('a real login grants device trust once the device is approved, and it shows on the unlock screen', function () {
    enablePinLogin();
    $user = User::factory()->create();
    $device = approvedDevice();

    withDeviceCookie($device)->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(DeviceTrustGrant::where('person_id', $user->id)->exists())->toBeTrue();

    $this->post('/logout');
    $response = withDeviceCookie($device)->get('/unlock');
    $response->assertInertia(fn ($page) => $page
        ->where('deviceApproved', true)
        ->where('people.0.id', $user->id));
});

test('a real login never grants device trust when the feature is disabled', function () {
    // feature left disabled (default)
    $user = User::factory()->create();
    $device = approvedDevice();

    withDeviceCookie($device)->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(DeviceTrustGrant::count())->toBe(0);
});

// ------------------------------------------------------------- PIN unlock

test('pin unlock succeeds with the correct pin on an active grant and logs the person in', function () {
    enablePinLogin();
    $device = approvedDevice();
    $user = userWithPin('24680');
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    $response = withDeviceCookie($device)->postJson('/unlock/pin', [
        'person_id' => $user->id, 'pin' => '24680',
    ]);

    $response->assertOk()->assertJsonStructure(['redirect']);
    $this->assertAuthenticatedAs($user);
});

test('pin unlock fails with an incorrect pin and does not log in', function () {
    enablePinLogin();
    $device = approvedDevice();
    $user = userWithPin('24680');
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    $response = withDeviceCookie($device)->postJson('/unlock/pin', [
        'person_id' => $user->id, 'pin' => '00000',
    ]);

    $response->assertStatus(401);
    $this->assertGuest();
});

test('pin unlock fails when there is no active grant for that person on this device', function () {
    enablePinLogin();
    $device = approvedDevice();
    $user = userWithPin('24680');
    // no grant created

    $response = withDeviceCookie($device)->postJson('/unlock/pin', [
        'person_id' => $user->id, 'pin' => '24680',
    ]);

    $response->assertStatus(401);
    $this->assertGuest();
});

test('pin unlock with a nonexistent person_id fails the same generic way as no grant, not a validation error', function () {
    enablePinLogin();
    $device = approvedDevice();

    $response = withDeviceCookie($device)->postJson('/unlock/pin', [
        'person_id' => 999999, 'pin' => '24680',
    ]);

    // Must not be a 422 validation error — that would let an unauthenticated
    // caller distinguish real from fake person IDs before the device-approval
    // gate even applies.
    $response->assertStatus(401);
    $this->assertGuest();
});

test('pin unlock fails when the device is not approved even with a correct pin', function () {
    enablePinLogin();
    $device = TrustedDevice::create(['device_token' => 'pending', 'status' => 'pending', 'requested_at' => now()]);
    $user = userWithPin('24680');
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    $response = withDeviceCookie($device)->postJson('/unlock/pin', [
        'person_id' => $user->id, 'pin' => '24680',
    ]);

    $response->assertStatus(403);
    $this->assertGuest();
});

test('pin unlock is rate limited after repeated incorrect attempts', function () {
    enablePinLogin();
    $device = approvedDevice();
    $user = userWithPin('24680');
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    for ($i = 0; $i < 5; $i++) {
        withDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $user->id, 'pin' => '00000']);
    }

    $response = withDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $user->id, 'pin' => '24680']);
    $response->assertStatus(429);
    $this->assertGuest();
});

test('revoking a device deletes its grants, immediately blocking pin unlock', function () {
    enablePinLogin();
    $device = approvedDevice();
    $user = userWithPin('24680');
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    $device->update(['status' => 'revoked']);
    $device->grants()->delete();

    $response = withDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $user->id, 'pin' => '24680']);
    $response->assertStatus(403);
});

// ------------------------------------------------------------- badge scan

test('scanning a recognized badge with an active grant identifies the person', function () {
    enablePinLogin();
    $device = approvedDevice();
    $user = User::factory()->create();
    $user->badge_code = 'BADGE-001';
    $user->save();
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    $response = withDeviceCookie($device)->postJson('/unlock/badge', ['badge_code' => 'BADGE-001']);

    $response->assertOk()->assertJsonPath('person.id', $user->id);
});

test('scanning an unrecognized badge fails', function () {
    enablePinLogin();
    $device = approvedDevice();

    $response = withDeviceCookie($device)->postJson('/unlock/badge', ['badge_code' => 'NOPE']);

    $response->assertStatus(404);
});

test('require_badge_and_pin blocks pin unlock until a badge was recently scanned', function () {
    enablePinLogin(['require_badge_and_pin' => true]);
    $device = approvedDevice();
    $user = userWithPin('24680');
    $user->badge_code = 'BADGE-002';
    $user->save();
    DeviceTrustGrant::create(['trusted_device_id' => $device->id, 'person_id' => $user->id, 'granted_at' => now()]);

    // No badge scan yet — tile-tap-equivalent PIN submission must be rejected.
    $response = withDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $user->id, 'pin' => '24680']);
    $response->assertStatus(422);
    $this->assertGuest();

    // Scan the badge first, then the same PIN succeeds.
    withDeviceCookie($device)->postJson('/unlock/badge', ['badge_code' => 'BADGE-002']);
    $response = withDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $user->id, 'pin' => '24680']);
    $response->assertOk();
    $this->assertAuthenticatedAs($user);
});

// ------------------------------------------------------------- trust expiry

test('session_duration trust mode expires a grant after the configured minutes', function () {
    $settings = enablePinLogin(['trust_mode' => 'session_duration', 'trust_session_minutes' => 30]);

    $grantedAt = now();
    $expiry = $settings->computeExpiry($grantedAt);

    expect($expiry->equalTo($grantedAt->copy()->addMinutes(30)))->toBeTrue();
});

test('indefinite trust mode never expires', function () {
    $settings = enablePinLogin(['trust_mode' => 'indefinite']);

    expect($settings->computeExpiry(now()))->toBeNull();
});

test('time_of_day trust mode expires at the next occurrence of that clock time', function () {
    $settings = enablePinLogin(['trust_mode' => 'time_of_day', 'trust_time_of_day' => '18:00']);

    $grantedAt = now()->setTime(10, 0); // before 18:00 today
    expect($settings->computeExpiry($grantedAt)->isSameDay($grantedAt))->toBeTrue();

    $grantedAt = now()->setTime(20, 0); // after 18:00 today
    expect($settings->computeExpiry($grantedAt)->isSameDay($grantedAt->copy()->addDay()))->toBeTrue();
});

test('an expired grant no longer permits pin unlock', function () {
    enablePinLogin(['trust_mode' => 'session_duration', 'trust_session_minutes' => 30]);
    $device = approvedDevice();
    $user = userWithPin('24680');
    DeviceTrustGrant::create([
        'trusted_device_id' => $device->id, 'person_id' => $user->id,
        'granted_at' => now()->subHours(2), 'expires_at' => now()->subHour(),
    ]);

    $response = withDeviceCookie($device)->postJson('/unlock/pin', ['person_id' => $user->id, 'pin' => '24680']);
    $response->assertStatus(401);
    $this->assertGuest();
});
