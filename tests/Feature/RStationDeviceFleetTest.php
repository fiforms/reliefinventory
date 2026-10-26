<?php

use App\Models\DeviceRelease;
use App\Models\FirmwareSetting;
use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Models\TrustPairingCode;
use App\Services\DeviceReleaseResolver;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\TrustService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function pairedDevice(array $overrides = []): array
{
    [$device, $token, $response, $peer] = pairClient(
        $overrides['device_uuid'] ?? 'uuid-1', $overrides['device_name'] ?? 'Pi One', $overrides['code'] ?? null,
    );

    return [$device, $token, $response, $peer];
}

function release(array $attrs): DeviceRelease
{
    return DeviceRelease::create(array_merge([
        'kind' => 'app', 'version' => '1.0.0', 'architecture' => 'aarch64', 'release_type' => 'full',
        'sha256' => str_repeat('a', 64), 'storage_path' => 'x', 'filename' => 'x.tar.gz',
        'download_token' => bin2hex(random_bytes(10)), 'channels' => [],
    ], $attrs));
}

test('pairing exchanges a single-use code and the device key for a trust', function () {
    [$device, $token, $response, $peer] = pairedDevice();

    $trust = $device->trust;
    expect($trust->public_key)->toBe($peer['public_key'])
        ->and($trust->kind)->toBe('print_client')
        ->and($device->token_hash)->toBeNull()
        ->and($response->json('entity_name'))->not->toBeNull()
        ->and($response->json('server.public_key'))->toBe(InstanceIdentity::current()->public_key)
        ->and($response->json('token'))->toBeNull();

    // The code is spent.
    $code = TrustPairingCode::first();
    expect($code->used_at)->not->toBeNull();
    $again = fedPeer('print_client');
    $this->postJson('/api/federation/v1/pair', fedPairBody($again, 'ABCDE-FGHJK'))->assertStatus(422);
});

test('expired and unknown pairing codes are rejected with one generic error', function () {
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    [$code] = TrustService::createPairingCode('client', [], null, $location->id, null);
    $this->travel(20)->minutes();

    $peer = fedPeer('print_client');
    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code))->assertStatus(422);
    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, 'ZZZZZ-ZZZZZ'))->assertStatus(422);
});

test('the old bearer pairing is retired', function () {
    $this->postJson('/api/devices/pair', ['code' => '123456', 'device_name' => 'Old Pi'])->assertStatus(410);
});

test('re-pairing the same device_uuid reuses the record, replaces the session, and clears revocation', function () {
    [$device, $oldToken] = pairedDevice();
    $device->trust->update(['status' => 'revoked']);
    $device->update(['revoked_at' => now()]);

    [$again, $new] = pairedDevice(['device_name' => 'Renamed']);

    expect(ManagedDevice::count())->toBe(1)
        ->and($again->id)->toBe($device->id)
        ->and($device->fresh()->revoked_at)->toBeNull()
        ->and($device->fresh()->name)->toBe('Renamed');

    $this->withToken($oldToken)->postJson('/api/devices/heartbeat', [])->assertStatus(401);
    $this->withToken($new)->postJson('/api/devices/heartbeat', [])->assertOk();
});

test('a device still holding an old bearer token works only while the legacy flag is on', function () {
    [$device] = pairedDevice();
    $device->forceFill(['token_hash' => ManagedDevice::hashToken('legacy-token')])->save();

    $this->withToken('legacy-token')->postJson('/api/devices/heartbeat', [])->assertStatus(401);
    config(['federation.legacy_device_tokens' => true]);
    $this->withToken('legacy-token')->postJson('/api/devices/heartbeat', [])->assertOk();
    $device->update(['revoked_at' => now()]);
    $this->withToken('legacy-token')->postJson('/api/devices/heartbeat', [])->assertStatus(401);
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
    $admin = userWithPermissions('manage-firmware');

    $record = $this->actingAs($admin)->postJson('/json/device-releases', [
        'file' => UploadedFile::fake()->createWithContent('app-1.0.0.tar.gz', 'payload'),
        'kind' => 'app', 'version' => '1.0.0', 'architecture' => 'aarch64', 'release_type' => 'full',
    ])->assertCreated()->json('record');

    expect($record['sha256'])->toBe(hash('sha256', 'payload'));
    $this->get($record['download_url'])->assertOk();
    $this->get('/api/devices/artifacts/wrongtoken/app-1.0.0.tar.gz')->assertNotFound();
});

test('a tagged release cannot be deleted', function () {
    $admin = userWithPermissions('manage-firmware');
    $r = release([]);
    app(DeviceReleaseResolver::class)->tag($r, 'stable');

    $this->actingAs($admin)->deleteJson("/json/device-releases/{$r->id}")->assertStatus(422);
});

test('admins can generate pairing codes, configure and revoke devices', function () {
    $admin = userWithPermissions('manage-devices');
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);

    $code = $this->actingAs($admin)->postJson('/json/device-pairing-codes', ['kiosk_location_id' => $location->id])
        ->assertCreated()->json('record.code');
    expect($code)->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');

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
    $this->actingAs($user)->get('/setup/devices')->assertStatus(403);
});

test('firmware endpoints need manage-firmware, which manage-devices alone does not grant', function () {
    $deviceAdmin = userWithPermissions('manage-devices');

    $this->actingAs($deviceAdmin)->getJson('/json/device-releases')->assertStatus(403);
    $this->actingAs($deviceAdmin)->postJson('/json/device-releases/uploads', [])->assertStatus(403);
    $this->actingAs($deviceAdmin)->putJson('/json/firmware-source', ['source_url' => 'https://x.example.org'])->assertStatus(403);
    $this->actingAs($deviceAdmin)->get('/setup/firmware')->assertStatus(403);
    $this->actingAs(userWithPermissions('manage-firmware'))->getJson('/json/device-releases')->assertOk();
});

function startChunkedUpload(array $overrides = []): array
{
    $admin = $overrides['admin'] ?? userWithPermissions('manage-firmware');
    unset($overrides['admin']);
    $response = test()->actingAs($admin)->postJson('/json/device-releases/uploads', array_merge([
        'filename' => 'fw 1.0.raucb', 'size' => 10, 'kind' => 'os', 'version' => '1.0.0_1.0.0', 'architecture' => 'aarch64', 'release_type' => 'full',
    ], $overrides))->assertCreated();

    return [$admin, $response->json('upload_id'), $response];
}

function putChunk(string $id, int $offset, string $bytes)
{
    return test()->call('PUT', "/json/device-releases/uploads/{$id}?offset={$offset}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $bytes);
}

test('a release can be uploaded in chunks and is hashed and stored once complete', function () {
    Storage::fake('local');
    [$admin, $id, $start] = startChunkedUpload();
    expect($start->json('chunk_size'))->toBeGreaterThan(0);

    $this->actingAs($admin);
    putChunk($id, 0, '01234')->assertOk()->assertJsonPath('received', 5);
    $this->getJson("/json/device-releases/uploads/{$id}")->assertJsonPath('received', 5);
    putChunk($id, 5, '56789')->assertOk()->assertJsonPath('received', 10);

    $record = $this->postJson("/json/device-releases/uploads/{$id}/complete")->assertCreated()->json('record');

    expect($record['sha256'])->toBe(hash('sha256', '0123456789'))
        ->and($record['size_bytes'])->toBe(10)
        ->and($record['filename'])->toBe('fw_1.0.raucb');
    $this->get($record['download_url'])->assertOk();
    $this->getJson("/json/device-releases/uploads/{$id}")->assertNotFound(); // the part file is gone
});

test('chunks must arrive in order, so a client can always resume from the server offset', function () {
    Storage::fake('local');
    [$admin, $id] = startChunkedUpload();
    $this->actingAs($admin);

    putChunk($id, 5, 'late!')->assertStatus(409)->assertJsonPath('received', 0);
    putChunk($id, 0, '01234')->assertOk();
    putChunk($id, 0, '01234')->assertStatus(409)->assertJsonPath('received', 5); // a retried chunk is not appended twice
    putChunk($id, 5, 'too long for the file')->assertStatus(422);
    $this->postJson("/json/device-releases/uploads/{$id}/complete")->assertStatus(409); // incomplete
});

test('an upload belongs to the person who started it', function () {
    Storage::fake('local');
    [, $id] = startChunkedUpload();

    $this->actingAs(userWithPermissions('manage-firmware'))->getJson("/json/device-releases/uploads/{$id}")->assertNotFound();
    $this->actingAs(userWithPermissions('manage-firmware'))->deleteJson("/json/device-releases/uploads/{$id}")->assertNotFound();
});

test('an upload id that is not a plain token is refused, and app releases must be full', function () {
    $admin = userWithPermissions('manage-firmware');

    $this->actingAs($admin)->getJson('/json/device-releases/uploads/'.str_repeat('..', 20))->assertNotFound();
    $this->actingAs($admin)->postJson('/json/device-releases/uploads', [
        'filename' => 'a.tar.gz', 'size' => 10, 'kind' => 'app', 'version' => '1.0.0', 'architecture' => 'aarch64',
        'release_type' => 'hotfix', 'required_base_version' => '0.9.0',
    ])->assertStatus(422);
});

test('print servers are told apart from R Stations and are never offered firmware', function () {
    [$piDevice, $piToken] = pairedDevice(['device_uuid' => 'pi']);
    [$pcDevice, $pcToken] = pairedDevice(['device_uuid' => 'pc']);
    app(DeviceReleaseResolver::class)->tag(release(['version' => '9.0.0']), 'stable');

    $this->withToken($piToken)->postJson('/api/devices/heartbeat', ['architecture' => 'aarch64', 'app_version' => '1.0.0'])
        ->assertJsonPath('app_update_available', true);
    $this->withToken($pcToken)->postJson('/api/devices/heartbeat', ['architecture' => 'aarch64', 'app_version' => '1.0.0', 'print_platform' => 'linux'])
        ->assertJsonPath('app_update_available', false);

    expect($piDevice->fresh()->device_type)->toBe('rstation')->and($pcDevice->fresh()->device_type)->toBe('print_server');
});

test('with a firmware source set the heartbeat points the device at it instead of offering releases', function () {
    [$device, $token] = pairedDevice();
    $device->update(['update_channel' => 'testing']);
    app(DeviceReleaseResolver::class)->tag(release(['version' => '9.0.0']), 'testing');
    $beat = fn () => $this->withToken($token)->postJson('/api/devices/heartbeat', ['architecture' => 'aarch64', 'app_version' => '1.0.0']);

    $beat()->assertJsonPath('update_server_url', null)->assertJsonPath('app_update_available', true);

    $admin = userWithPermissions('manage-firmware');
    $this->actingAs($admin)->putJson('/json/firmware-source', ['source_url' => 'https://master.example.org/'])
        ->assertOk()->assertJsonPath('source_url', 'https://master.example.org');
    $beat()->assertJsonPath('update_server_url', 'https://master.example.org')
        ->assertJsonPath('update_channel', 'testing')->assertJsonPath('app_update_available', false);

    $this->actingAs($admin)->putJson('/json/firmware-source', ['source_url' => null])->assertOk();
    $beat()->assertJsonPath('update_server_url', null);
});

test('the firmware source must be an http(s) URL and not this server', function () {
    $admin = userWithPermissions('manage-firmware');

    foreach (['javascript:alert(1)', 'ftp://x.example.org', 'not a url', url('/')] as $bad) {
        $this->actingAs($admin)->putJson('/json/firmware-source', ['source_url' => $bad])->assertStatus(422);
    }
});

test('another instance\'s device can ask this catalogue directly, without a token', function () {
    app(DeviceReleaseResolver::class)->tag(release(['version' => '3.0.0', 'sha256' => str_repeat('b', 64)]), 'testing');
    // Even when this instance is itself pointed elsewhere, it answers from its own releases only.
    FirmwareSetting::current()->update(['source_url' => 'https://elsewhere.example.org']);

    $this->postJson('/api/devices/update-check', ['architecture' => 'aarch64', 'app_version' => '1.0.0', 'channel' => 'testing'])
        ->assertOk()->assertJsonPath('app_update_available', true)->assertJsonPath('latest_app_version', '3.0.0')
        ->assertJsonPath('app_sha256', str_repeat('b', 64))->assertJsonPath('os_update_available', false);
    $this->postJson('/api/devices/update-check', ['architecture' => 'aarch64', 'app_version' => '1.0.0', 'channel' => 'stable'])
        ->assertJsonPath('app_update_available', false); // not tagged on stable
    $this->postJson('/api/devices/update-check', ['channel' => 'stable'])->assertStatus(422);
    $this->postJson('/api/devices/update-check', ['architecture' => 'aarch64', 'channel' => 'bogus'])->assertStatus(422);
});

test('images and the print client are download-only, for anyone who can pair devices', function () {
    Storage::fake('local');
    $uploader = userWithPermissions('manage-firmware');
    $pairer = userWithPermissions('manage-devices');
    $nobody = userWithPermissions('general-access');

    $upload = fn ($meta, $name = 'file.bin') => $this->actingAs($uploader)->postJson('/json/device-releases', [
        'file' => UploadedFile::fake()->createWithContent($name, 'payload'), 'version' => '1.0.0',
    ] + $meta);

    $vm = $upload(['kind' => 'os', 'architecture' => 'x86_64', 'release_type' => 'vm_image'], 'ri.img.xz')->assertCreated()->json('record');
    $sd = $upload(['kind' => 'os', 'architecture' => 'aarch64', 'release_type' => 'disk_image'], 'ri-pi.img.xz')->assertCreated()->json('record');
    $pc = $upload(['kind' => 'print_client', 'architecture' => 'macos-arm64', 'release_type' => 'full'], 'ri-print.dmg')->assertCreated()->json('record');

    // Unknown platforms, non-full print clients and images outside the OS kind are refused.
    $upload(['kind' => 'print_client', 'architecture' => 'beos', 'release_type' => 'full'])->assertStatus(422);
    $upload(['kind' => 'print_client', 'architecture' => 'windows', 'release_type' => 'hotfix', 'required_base_version' => '1'])->assertStatus(422);
    $upload(['kind' => 'app', 'architecture' => 'aarch64', 'release_type' => 'vm_image'])->assertStatus(422);

    $this->actingAs($pairer)->getJson('/json/device-downloads')->assertOk()->assertJsonCount(3, 'records');
    foreach ([$vm, $sd, $pc] as $record) {
        $this->actingAs($pairer)->get($record['download_url'])->assertOk();
        $this->actingAs($nobody)->get($record['download_url'])->assertForbidden();
        $this->actingAs($pairer)->get('/api/devices/artifacts/'.DeviceRelease::find($record['id'])->download_token.'/'.$record['filename'])->assertNotFound();
    }
    $this->actingAs($nobody)->getJson('/json/device-downloads')->assertForbidden();
});

test('a new image or print client upload expires the older one for the same platform only', function () {
    Storage::fake('local');
    $uploader = userWithPermissions('manage-firmware');
    $upload = fn ($meta, $version) => $this->actingAs($uploader)->postJson('/json/device-releases', [
        'file' => UploadedFile::fake()->createWithContent('f-'.$version.'.bin', 'payload'), 'version' => $version,
    ] + $meta)->assertCreated()->json('record');

    $win1 = $upload(['kind' => 'print_client', 'architecture' => 'windows', 'release_type' => 'full'], '1.0');
    $mac = $upload(['kind' => 'print_client', 'architecture' => 'macos-x64', 'release_type' => 'full'], '1.0');
    $vm = $upload(['kind' => 'os', 'architecture' => 'x86_64', 'release_type' => 'vm_image'], '1.0');
    $win2 = $upload(['kind' => 'print_client', 'architecture' => 'windows', 'release_type' => 'full'], '1.1');
    $vm2 = $upload(['kind' => 'os', 'architecture' => 'x86_64', 'release_type' => 'vm_image'], '1.1');

    expect(DeviceRelease::pluck('id')->all())->toEqualCanonicalizing([$mac['id'], $win2['id'], $vm2['id']]);
    expect(Storage::disk('local')->directories('device-releases'))->toHaveCount(3); // the old files went too
});
