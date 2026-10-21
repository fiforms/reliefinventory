<?php

use App\Models\DeviceSessionTicket;
use App\Models\KioskLocation;
use App\Models\ManagedDevice;

function sessionDevice(string $function = 'status_sitrep'): array
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $device = new ManagedDevice(['name' => 'Pi', 'update_channel' => 'stable', 'kiosk_function' => $function, 'kiosk_location_id' => $location->id]);
    $device->device_uuid = 'sess-'.bin2hex(random_bytes(4));
    $token = $device->issueToken();
    $device->save();

    return [$device, $token];
}

function ticketFor(string $token): string
{
    return test()->postJson('/api/devices/session-ticket', [], ['Authorization' => "Bearer $token"])->assertOk()->json('ticket');
}

/** Runs the whole handoff; returns the cookie value the browser would hold. */
function browserSessionFor(string $token): string
{
    return test()->get('/device-session/'.ticketFor($token))->getCookie(ManagedDevice::SESSION_COOKIE)->getValue();
}

function asBrowser(string $cookie)
{
    return test()->withCredentials()->withCookie(ManagedDevice::SESSION_COOKIE, $cookie);
}

test('a ticket needs a valid device token', function () {
    $this->postJson('/api/devices/session-ticket')->assertUnauthorized();
    $this->postJson('/api/devices/session-ticket', [], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});

test('redeeming a ticket sets the cookie and lands on the device function page', function () {
    [$device, $token] = sessionDevice('status_sitrep');
    $ticket = ticketFor($token);

    $response = $this->get("/device-session/$ticket")->assertRedirect('/display/sitrep');
    $cookie = $response->getCookie(ManagedDevice::SESSION_COOKIE, false);
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue();
    expect($device->fresh()->session_token_hash)->not->toBeNull();
    // Only a hash of the ticket is stored.
    expect(DeviceSessionTicket::where('ticket_hash', $ticket)->exists())->toBeFalse();
});

test('a ticket is single use, expires, and a bad one falls back to login', function () {
    [, $token] = sessionDevice();
    $ticket = ticketFor($token);

    $this->get("/device-session/$ticket")->assertRedirect('/display/sitrep');
    $this->get("/device-session/$ticket")->assertRedirect('/login');

    $expired = ticketFor($token);
    DeviceSessionTicket::query()->update(['expires_at' => now()->subSecond()]);
    $this->get("/device-session/$expired")->assertRedirect('/login');
    $this->get('/device-session/garbage')->assertRedirect('/login');
});

test('a device whose function has no page lands on login', function () {
    [, $token] = sessionDevice('print_station');
    $this->get('/device-session/'.ticketFor($token))->assertRedirect('/login');
});

test('the session opens only the pages for the device function', function () {
    [, $token] = sessionDevice('status_sitrep');
    $cookie = browserSessionFor($token);

    asBrowser($cookie)->get('/display/sitrep')->assertOk();
    asBrowser($cookie)->getJson('/json/display/sitrep')->assertOk()->assertJsonStructure(['pipeline']);
    asBrowser($cookie)->get('/display/dashboard')->assertRedirect('/login');
    asBrowser($cookie)->getJson('/json/display/dashboard')->assertForbidden();
    asBrowser($cookie)->getJson('/json/dashboard')->assertUnauthorized();
    asBrowser($cookie)->getJson('/json/sitrep')->assertUnauthorized();
    asBrowser($cookie)->getJson('/json/people')->assertUnauthorized();
});

test('the dashboard display omits donor quality', function () {
    [, $token] = sessionDevice('status_dashboard');
    $json = asBrowser(browserSessionFor($token))->getJson('/json/display/dashboard')->assertOk()->json();

    expect($json)->toHaveKey('pipeline')->not->toHaveKey('donor_quality');
});

test('no cookie, a forged cookie, or a changed function means no access', function () {
    [$device, $token] = sessionDevice('status_sitrep');
    $cookie = browserSessionFor($token);

    $this->getJson('/json/display/sitrep')->assertForbidden();
    test()->withCredentials()->withCookie(ManagedDevice::SESSION_COOKIE, 'forged')->getJson('/json/display/sitrep')->assertForbidden();

    $device->update(['kiosk_function' => 'print_station']);
    asBrowser($cookie)->getJson('/json/display/sitrep')->assertForbidden();
});

test('re-pairing a device kills its browser session', function () {
    [$device, $token] = sessionDevice();
    $cookie = browserSessionFor($token);
    asBrowser($cookie)->getJson('/json/display/sitrep')->assertOk();

    $device->refresh()->issueToken(); // what a re-pair does
    $device->save();
    asBrowser($cookie)->getJson('/json/display/sitrep')->assertForbidden();
});

test('revoking a device kills its session and stops new ones', function () {
    [$device, $token] = sessionDevice();
    $cookie = browserSessionFor($token);
    $pending = ticketFor($token);

    $device->update(['revoked_at' => now()]);
    asBrowser($cookie)->getJson('/json/display/sitrep')->assertForbidden();
    $this->get("/device-session/$pending")->assertRedirect('/login');
});

test('a volunteer check-in device reaches the kiosk page and its endpoints, other devices do not', function () {
    [, $token] = sessionDevice('volunteer_checkin');
    $cookie = browserSessionFor($token);
    asBrowser($cookie)->get('/volunteers/kiosk')->assertOk();
    asBrowser($cookie)->getJson('/json/kiosk-suggestions?kind=agency')->assertOk();

    [, $other] = sessionDevice('status_sitrep');
    asBrowser(browserSessionFor($other))->get('/volunteers/kiosk')->assertRedirect('/login');
});

test('a logged-in user with the permission can open a display page without a device', function () {
    $this->actingAs(userWithPermissions('view-sitrep'))->get('/display/sitrep')->assertOk();
});

test('the session hash is never serialized to the admin UI', function () {
    [$device, $token] = sessionDevice();
    browserSessionFor($token);

    expect($device->fresh()->toArray())->not->toHaveKey('session_token_hash');
});
