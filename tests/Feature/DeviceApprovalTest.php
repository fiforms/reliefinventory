<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// PIN login on a device is asked for from that device (ThisDeviceController): an approver approves it at once,
// anyone else files a request (optional station name + reason) an approver approves or denies.

use App\Models\DeviceTrustGrant;
use App\Models\PinLoginSetting;
use App\Models\TrustedDevice;
use App\Notifications\DeviceApprovalRequested;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Notification;

const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

function onThisDevice(TrustedDevice $device)
{
    return test()->withCredentials()->withCookie('pin_device_token', $device->device_token);
}

test('a request from someone who cannot approve waits for an approver, with what it came from', function () {
    Notification::fake();
    $approver = userWithPermissions('general-access', 'manage-trusted-devices');
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->withHeaders(['User-Agent' => CHROME_WINDOWS])
        ->postJson('/json/this-device', ['label' => 'Sorting Station 1', 'reason' => 'Shared tablet'])
        ->assertOk()
        ->assertCookie('pin_device_token')
        ->assertJsonPath('device.status', 'pending')
        ->assertJsonPath('can_approve', false);

    $device = TrustedDevice::sole();
    expect($device->only(['status', 'label', 'request_reason', 'requested_by_person_id', 'ip_address', 'browser', 'os']))->toBe([
        'status' => 'pending', 'label' => 'Sorting Station 1', 'request_reason' => 'Shared tablet',
        'requested_by_person_id' => $user->id, 'ip_address' => '127.0.0.1', 'browser' => 'Chrome 141', 'os' => 'Windows 10/11',
    ]);
    Notification::assertSentTo($approver, DeviceApprovalRequested::class);
    Notification::assertNotSentTo($user, DeviceApprovalRequested::class);

    // Updating the waiting request doesn't ring the bell again.
    onThisDevice($device)->actingAs($user)->postJson('/json/this-device', ['label' => 'Sorting 1', 'reason' => null])->assertOk();
    expect(TrustedDevice::count())->toBe(1)->and($device->fresh()->label)->toBe('Sorting 1');
    Notification::assertSentToTimes($approver, DeviceApprovalRequested::class, 1);

    // The approver sees it and approves it.
    $this->actingAs($approver)->getJson('/json/trusted-devices')
        ->assertJsonPath('records.0.requester.id', $user->id)->assertJsonPath('records.0.status', 'pending');
    $this->actingAs($approver)->postJson("/json/trusted-devices/{$device->id}/approve", ['label' => 'Sorting Station 1'])->assertOk();
    expect($device->fresh()->isApproved())->toBeTrue();
    onThisDevice($device)->actingAs($user)->getJson('/json/this-device')->assertJsonPath('device.status', 'approved');
});

test('an approver approves the device they are on at once, and gets PIN trust there', function () {
    PinLoginSetting::current()->update(['enabled' => true]);
    $approver = userWithPermissions('general-access', 'manage-trusted-devices');

    $this->actingAs($approver)->postJson('/json/this-device', [])->assertOk()->assertJsonPath('device.status', 'approved');

    $device = TrustedDevice::sole();
    expect($device->approved_by)->toBe($approver->id)
        ->and(DeviceTrustGrant::where('trusted_device_id', $device->id)->where('person_id', $approver->id)->exists())->toBeTrue();

    onThisDevice($device)->actingAs($approver)->postJson('/json/this-device', [])->assertStatus(409);
});

test('a pending request can be denied, and only a pending one', function () {
    $approver = userWithPermissions('general-access', 'manage-trusted-devices');
    $device = TrustedDevice::create(['device_token' => 't1', 'status' => 'pending', 'requested_at' => now()]);

    $this->actingAs($approver)->postJson("/json/trusted-devices/{$device->id}/deny")->assertOk()->assertJsonPath('record.status', 'denied');
    $this->actingAs($approver)->postJson("/json/trusted-devices/{$device->id}/deny")->assertNotFound();
    $this->actingAs(userWithPermissions('general-access'))->postJson("/json/trusted-devices/{$device->id}/approve")->assertForbidden();
});

test('a denied device can ask again', function () {
    Notification::fake();
    $user = userWithPermissions('general-access');
    $device = TrustedDevice::create(['device_token' => 't2', 'status' => 'denied', 'requested_at' => now()->subDay()]);

    onThisDevice($device)->actingAs($user)->postJson('/json/this-device', ['reason' => 'Moved to the dock'])
        ->assertOk()->assertJsonPath('device.status', 'pending');
    expect($device->fresh()->request_reason)->toBe('Moved to the dock');
});

test('the page renders for any signed-in user', function () {
    $this->actingAs(userWithPermissions('general-access'))->get('/this-device')
        ->assertInertia(fn ($page) => $page->component('ThisDevice'));
});

test('browser and OS are read from the user agent', function (string $ua, ?string $browser, ?string $os) {
    expect(UserAgent::parse($ua))->toBe(['browser' => $browser, 'os' => $os]);
})->with([
    [CHROME_WINDOWS, 'Chrome 141', 'Windows 10/11'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge 141', 'Windows 10/11'],
    ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari 18', 'iPadOS'],
    ['Mozilla/5.0 (Linux; Android 14; SM-T220) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36', 'Samsung Internet 25', 'Android 14'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox 131', 'macOS'],
    ['Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome 130', 'Linux'],
    ['curl/8.0', null, null],
]);
