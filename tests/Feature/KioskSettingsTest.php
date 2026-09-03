<?php

use App\Models\KioskLocation;
use App\Models\PinLoginSetting;
use App\Models\TrustedDevice;

test('kiosk locations can be listed, created, and updated by a manage-kiosk user', function () {
    $user = userWithPermissions('manage-kiosk');

    $this->actingAs($user)->getJson('/json/kiosk-locations')->assertOk()
        ->assertJsonCount(1, 'records'); // the seeded default location

    $record = $this->actingAs($user)->postJson('/json/kiosk-locations', [
        'name' => 'Second Site',
        'welcome_message' => 'Hello there',
    ])->assertCreated()->json('record');

    $this->actingAs($user)->putJson("/json/kiosk-locations/{$record['id']}", [
        'name' => 'Second Site',
        'welcome_message' => '',
        'active' => false,
    ])->assertOk();

    expect(KioskLocation::find($record['id'])->active)->toBeFalse();
});

test('a non-manage-kiosk user cannot manage kiosk locations', function () {
    $user = userWithPermissions('operate-kiosk');

    $this->actingAs($user)->postJson('/json/kiosk-locations', ['name' => 'Nope'])->assertStatus(403);
});

test('sign-in categories (guest types) are scoped per location and can share a name across locations', function () {
    $admin = userWithPermissions('manage-kiosk');
    $locationA = KioskLocation::first(); // seeded default
    $locationB = KioskLocation::create(['name' => 'Site B']);

    $this->actingAs($admin)->postJson('/json/sign-in-categories', [
        'kiosk_location_id' => $locationA->id,
        'name' => 'FEMA',
    ])->assertStatus(422); // already seeded on the default location by the migration

    $this->actingAs($admin)->postJson('/json/sign-in-categories', [
        'kiosk_location_id' => $locationB->id,
        'name' => 'FEMA',
    ])->assertCreated();

    $operator = userWithPermissions('operate-kiosk');
    $records = $this->actingAs($operator)
        ->getJson("/json/sign-in-categories?kiosk_location_id={$locationB->id}")
        ->assertOk()->json('records');

    expect(collect($records)->pluck('name')->all())->toBe(['FEMA']);
});

test('kiosk suggestions are scoped per kind and reachable by an operator', function () {
    $admin = userWithPermissions('manage-kiosk');

    $this->actingAs($admin)->postJson('/json/kiosk-suggestions', ['kind' => 'agency', 'value' => 'American Red Cross'])
        ->assertCreated();
    $this->actingAs($admin)->postJson('/json/kiosk-suggestions', ['kind' => 'task', 'value' => 'Forklift Operator'])
        ->assertCreated();

    $operator = userWithPermissions('operate-kiosk');
    $agency = $this->actingAs($operator)->getJson('/json/kiosk-suggestions?kind=agency')->assertOk()->json('records');

    expect(collect($agency)->pluck('value')->all())->toBe(['American Red Cross']);
});

test('enabling kiosk mode auto-assigns the sole active location', function () {
    $settings = tap(PinLoginSetting::current())->update(['enabled' => true]);
    $device = TrustedDevice::create([
        'device_token' => 'loc-test-'.uniqid(),
        'status' => 'approved',
        'requested_at' => now(),
        'approved_at' => now(),
    ]);
    $operator = userWithPermissions('operate-kiosk');

    $this->withCredentials()->withCookie('pin_device_token', $device->device_token)
        ->actingAs($operator)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertOk();

    expect($device->fresh()->kiosk_location_id)->toBe(KioskLocation::first()->id);
});

test('enabling kiosk mode requires a location when more than one is active', function () {
    KioskLocation::create(['name' => 'Second Site']);
    tap(PinLoginSetting::current())->update(['enabled' => true]);
    $device = TrustedDevice::create([
        'device_token' => 'loc-test-2-'.uniqid(),
        'status' => 'approved',
        'requested_at' => now(),
        'approved_at' => now(),
    ]);
    $operator = userWithPermissions('operate-kiosk');

    $this->withCredentials()->withCookie('pin_device_token', $device->device_token)
        ->actingAs($operator)
        ->postJson('/json/volunteer-kiosk/enable-lock')
        ->assertStatus(422);

    $secondSite = KioskLocation::where('name', 'Second Site')->first();
    $this->withCredentials()->withCookie('pin_device_token', $device->device_token)
        ->actingAs($operator)
        ->postJson('/json/volunteer-kiosk/enable-lock', ['location_id' => $secondSite->id])
        ->assertOk();

    expect($device->fresh()->kiosk_location_id)->toBe($secondSite->id);
});
