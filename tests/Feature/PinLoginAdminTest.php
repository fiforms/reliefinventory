<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\TrustedDevice;

test('pin login settings require the admin-system permission', function () {
    $user = userWithPermissions('manage-trusted-devices'); // has the narrower permission, not admin-system

    $this->actingAs($user)->getJson('/json/pin-login-settings')->assertForbidden();
    $this->actingAs($user)->putJson('/json/pin-login-settings', ['enabled' => true])->assertForbidden();
});

test('an admin-system holder can view and update pin login settings', function () {
    $user = userWithPermissions('admin-system');

    $this->actingAs($user)->getJson('/json/pin-login-settings')->assertOk()
        ->assertJsonPath('settings.enabled', false);

    $this->actingAs($user)->putJson('/json/pin-login-settings', [
        'enabled' => true,
        'trust_mode' => 'indefinite',
        'require_badge_and_pin' => false,
        'badge_login_enabled' => false,
    ])->assertOk()->assertJsonPath('settings.enabled', true);
});

test('updating to session_duration mode requires a duration', function () {
    $user = userWithPermissions('admin-system');

    $this->actingAs($user)->putJson('/json/pin-login-settings', [
        'enabled' => true,
        'trust_mode' => 'session_duration',
        'trust_session_minutes' => null,
        'require_badge_and_pin' => false,
        'badge_login_enabled' => false,
    ])->assertStatus(422);
});

test('trusted devices require the manage-trusted-devices permission, not just admin-system', function () {
    $user = userWithPermissions('admin-system'); // does NOT include manage-trusted-devices by default per PermissionsSeeder

    $this->actingAs($user)->getJson('/json/trusted-devices')->assertForbidden();
});

test('a manage-trusted-devices holder can list, approve, relabel, and revoke devices', function () {
    $user = userWithPermissions('manage-trusted-devices');
    $device = TrustedDevice::create(['device_token' => 'abc', 'status' => 'pending', 'requested_at' => now()]);

    $this->actingAs($user)->getJson('/json/trusted-devices')->assertOk()
        ->assertJsonCount(1, 'records');

    $this->actingAs($user)->postJson("/json/trusted-devices/{$device->id}/approve", [
        'label' => 'Sorting Station 1',
    ])->assertOk()->assertJsonPath('record.status', 'approved')->assertJsonPath('record.label', 'Sorting Station 1');

    $this->actingAs($user)->putJson("/json/trusted-devices/{$device->id}", ['label' => 'Renamed'])
        ->assertOk()->assertJsonPath('record.label', 'Renamed');

    $this->actingAs($user)->postJson("/json/trusted-devices/{$device->id}/revoke")
        ->assertOk()->assertJsonPath('record.status', 'revoked');

    expect($device->fresh()->status)->toBe('revoked');
});

test('the pin-login admin page loads for a general-access user and reports their actual permissions', function () {
    $limited = userWithPermissions('general-access');
    $response = $this->actingAs($limited)->get('/setup/pin-login');
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('canManageSettings', false)
        ->where('canManageDevices', false));

    $admin = userWithPermissions('admin-system', 'manage-trusted-devices', 'general-access');
    $response = $this->actingAs($admin)->get('/setup/pin-login');
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('canManageSettings', true)
        ->where('canManageDevices', true));
});
