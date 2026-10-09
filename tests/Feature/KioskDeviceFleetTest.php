<?php

use App\Models\DevicePairingCode;
use App\Models\DeviceRelease;
use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Services\DeviceReleaseResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function pairedDevice(array $overrides = []): array
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $code = DevicePairingCode::create([
        'code' => '123456', 'kiosk_location_id' => $location->id, 'expires_at' => now()->addMinutes(5),
    ]);
    $response = test()->postJson('/api/devices/pair', array_merge([
        'code' => $code->code, 'device_name' => 'Pi One', 'device_uuid' => 'uuid-1',
    ], $overrides))->assertCreated();

    return [ManagedDevice::where('device_uuid', $overrides['device_uuid'] ?? 'uuid-1')->first(), $response->json('token'), $response];
}

function release(array $attrs): DeviceRelease
{
    return DeviceRelease::create(array_merge([
        'kind' => 'app', 'version' => '1.0.0', 'architecture' => 'aarch64', 'release_type' => 'full',
        'sha256' => str_repeat('a', 64), 'storage_path' => 'x', 'filename' => 'x.tar.gz',
        'download_token' => bin2hex(random_bytes(10)), 'channels' => [],
    ], $attrs));
}

test('pairing exchanges a single-use code for a token', function () {
    [$device, $token, $response] = pairedDevice();

    expect($token)->not->toBeEmpty()
        ->and($device->token_hash)->toBe(ManagedDevice::hashToken($token))
        ->and($response->json('entity_name'))->not->toBeNull();

    // The code is spent.
    $this->postJson('/api/devices/pair', ['code' => '123456', 'device_name' => 'Pi Two', 'device_uuid' => 'uuid-2'])
        ->assertStatus(422);
});

test('expired and unknown pairing codes are rejected with one generic error', function () {
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    DevicePairingCode::create(['code' => '111111', 'kiosk_location_id' => $location->id, 'expires_at' => now()->subMinute()]);

    $this->postJson('/api/devices/pair', ['code' => '111111', 'device_name' => 'X'])->assertStatus(422);
    $this->postJson('/api/devices/pair', ['code' => '999999', 'device_name' => 'X'])->assertStatus(422);
});

test('re-pairing the same device_uuid reuses the record, replaces the token, and clears revocation', function () {
    [$device, $oldToken] = pairedDevice();
    $device->update(['revoked_at' => now()]);

    DevicePairingCode::create(['code' => '222222', 'kiosk_location_id' => $device->kiosk_location_id, 'expires_at' => now()->addMinutes(5)]);
    $new = $this->postJson('/api/devices/pair', ['code' => '222222', 'device_name' => 'Renamed', 'device_uuid' => 'uuid-1'])
        ->assertCreated()->json('token');

    expect(ManagedDevice::count())->toBe(1)
        ->and($device->fresh()->revoked_at)->toBeNull()
        ->and($device->fresh()->name)->toBe('Renamed');

    $this->withToken($oldToken)->postJson('/api/devices/heartbeat', [])->assertStatus(401);
    $this->withToken($new)->postJson('/api/devices/heartbeat', [])->assertOk();
});

test('heartbeat requires a device token and rejects revoked ones with 401', function () {
    $this->postJson('/api/devices/heartbeat', [])->assertStatus(401);
    $this->withToken('nope')->postJson('/api/devices/heartbeat', [])->assertStatus(401);

    [$device, $token] = pairedDevice();
    $device->update(['revoked_at' => now()]);
    $this->withToken($token)->postJson('/api/devices/heartbeat', [])->assertStatus(401);
});

test('heartbeat stores the snapshot without blanking omitted fields, and returns settings', function () {
    [$device, $token] = pairedDevice();
    $device->update(['kiosk_function' => 'volunteer_checkin']);

    $this->withToken($token)->postJson('/api/devices/heartbeat', [
        'app_version' => '0.1.0', 'os_version' => '0.4.0_0.1.0', 'architecture' => 'aarch64', 'hostname' => 'pi-one', 'cpu_temp_c' => 51.5,
    ])->assertOk()
        ->assertJsonPath('device_name', 'Pi One')
        ->assertJsonPath('app_update_available', false)
        ->assertJsonPath('kiosk_function', 'volunteer_checkin')
        ->assertJsonPath('kiosk_path', '/volunteers/kiosk');

    $this->withToken($token)->postJson('/api/devices/heartbeat', ['cpu_temp_c' => null, 'hostname' => null])->assertOk();

    $device->refresh();
    expect($device->app_version)->toBe('0.1.0')->and($device->hostname)->toBe('pi-one')
        ->and($device->last_seen_at)->not->toBeNull()->and($device->online)->toBeTrue();
});

test('language change applies only when base_revision is current', function () {
    [$device, $token] = pairedDevice();
    $rev = $device->language_revision;

    $this->withToken($token)->postJson('/api/devices/heartbeat', ['language_change' => ['code' => 'es', 'base_revision' => $rev]])
        ->assertJsonPath('language', 'es')->assertJsonPath('language_revision', $rev + 1);

    $this->withToken($token)->postJson('/api/devices/heartbeat', ['language_change' => ['code' => 'fr', 'base_revision' => $rev]])
        ->assertJsonPath('language', 'es');
});

test('version comparison handles pairs, plain versions and hash suffixes', function () {
    $cmp = fn ($a, $b) => DeviceReleaseResolver::compareVersions($a, $b);

    expect($cmp('0.4.0_0.1.1', '0.4.0_0.1.0'))->toBeGreaterThan(0)
        ->and($cmp('0.5.0_0.0.1', '0.4.9_9.9.9'))->toBeGreaterThan(0)
        ->and($cmp('1.2.3', '1.2.3-abc123'))->toBe(0)
        ->and($cmp('0.1.0', '0.1.1'))->toBeLessThan(0);
});

test('updates are offered per channel and architecture, never as downgrades', function () {
    [$device, $token] = pairedDevice();
    $resolver = app(DeviceReleaseResolver::class);
    $beat = fn (array $extra = []) => $this->withToken($token)->postJson('/api/devices/heartbeat', array_merge(
        ['app_version' => '1.0.0', 'os_version' => '1.0.0_1.0.0', 'architecture' => 'aarch64'], $extra));

    $newer = release(['version' => '1.1.0']);
    $beat()->assertJsonPath('app_update_available', false); // untagged = archived

    $resolver->tag($newer, 'stable');
    $beat()->assertJsonPath('app_update_available', true)
        ->assertJsonPath('latest_app_version', '1.1.0')
        ->assertJsonPath('app_sha256', str_repeat('a', 64));

    $beat(['app_version' => '1.1.0'])->assertJsonPath('app_update_available', false);
    $beat(['app_version' => '1.2.0'])->assertJsonPath('app_update_available', false); // no downgrade

    $other = release(['version' => '2.0.0', 'architecture' => 'armv7l']);
    $resolver->tag($other, 'stable');
    $beat()->assertJsonPath('latest_app_version', '1.1.0'); // wrong arch ignored

    $device->update(['update_channel' => 'testing']);
    $beat()->assertJsonPath('app_update_available', false); // not tagged on testing
});

test('tagging a full release evicts the previous one in the same slot but not other slots', function () {
    $resolver = app(DeviceReleaseResolver::class);
    $a = release(['version' => '1.0.0']);
    $b = release(['version' => '1.1.0']);
    $osFull = release(['kind' => 'os', 'version' => '1.0.0_1.0.0']);

    $resolver->tag($a, 'stable');
    $resolver->tag($osFull, 'stable');
    $resolver->tag($b, 'stable');

    expect($a->fresh()->channels)->toBe([])
        ->and($b->fresh()->channels)->toBe(['stable'])
        ->and($osFull->fresh()->channels)->toBe(['stable']);
});

test('an OS hotfix is offered only on top of its exact base version', function () {
    [$device, $token] = pairedDevice();
    $resolver = app(DeviceReleaseResolver::class);
    $resolver->tag(release(['kind' => 'os', 'version' => '2.0.0_1.0.0']), 'stable');
    $resolver->tag(release([
        'kind' => 'os', 'version' => '1.0.1_1.0.0', 'release_type' => 'hotfix', 'required_base_version' => '1.0.0_1.0.0',
    ]), 'stable');
    $beat = fn ($os) => $this->withToken($token)->postJson('/api/devices/heartbeat', ['os_version' => $os, 'architecture' => 'aarch64']);

    $beat('1.0.0_1.0.0')->assertJsonPath('latest_os_version', '1.0.1_1.0.0')->assertJsonPath('os_release_type', 'hotfix');
    $beat('1.0.1_1.0.0')->assertJsonPath('latest_os_version', '2.0.0_1.0.0')->assertJsonPath('os_release_type', 'full');
});

test('release upload stores a hashed artifact that devices can download by token', function () {
    Storage::fake('local');
    $admin = userWithPermissions('manage-devices');

    $record = $this->actingAs($admin)->postJson('/json/device-releases', [
        'file' => UploadedFile::fake()->createWithContent('app-1.0.0.tar.gz', 'payload'),
        'kind' => 'app', 'version' => '1.0.0', 'architecture' => 'aarch64', 'release_type' => 'full',
    ])->assertCreated()->json('record');

    expect($record['sha256'])->toBe(hash('sha256', 'payload'));
    $this->get($record['download_url'])->assertOk();
    $this->get('/api/devices/artifacts/wrongtoken/app-1.0.0.tar.gz')->assertNotFound();
});

test('a tagged release cannot be deleted', function () {
    $admin = userWithPermissions('manage-devices');
    $r = release([]);
    app(DeviceReleaseResolver::class)->tag($r, 'stable');

    $this->actingAs($admin)->deleteJson("/json/device-releases/{$r->id}")->assertStatus(422);
});

test('admins can generate pairing codes, configure and revoke devices', function () {
    $admin = userWithPermissions('manage-devices');
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);

    $code = $this->actingAs($admin)->postJson('/json/device-pairing-codes', ['kiosk_location_id' => $location->id])
        ->assertCreated()->json('record.code');
    expect($code)->toMatch('/^\d{6}$/');

    [$device, $token] = pairedDevice(['code' => $code]);

    $this->actingAs($admin)->putJson("/json/managed-devices/{$device->id}", [
        'name' => 'Dock Kiosk', 'update_channel' => 'testing', 'kiosk_function' => 'inventory_sorting', 'os_auto_update_enabled' => false,
    ])->assertOk();
    $this->withToken($token)->postJson('/api/devices/heartbeat', [])
        ->assertJsonPath('device_name', 'Dock Kiosk')->assertJsonPath('kiosk_path', '/donation-sorting');

    $this->actingAs($admin)->postJson("/json/managed-devices/{$device->id}/revoke")->assertOk();
    $this->withToken($token)->postJson('/api/devices/heartbeat', [])->assertStatus(401);
});

test('device admin endpoints require manage-devices', function () {
    $user = userWithPermissions('manage-kiosk');

    $this->actingAs($user)->getJson('/json/managed-devices')->assertStatus(403);
    $this->actingAs($user)->getJson('/json/device-releases')->assertStatus(403);
    $this->actingAs($user)->get('/setup/devices')->assertStatus(403);
});
