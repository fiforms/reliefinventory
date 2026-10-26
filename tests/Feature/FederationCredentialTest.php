<?php

use App\Http\Controllers\FederationController;
use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Models\Trust;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;

/** The Host a test request carries (APP_URL's host and any non-default port). */
function credentialHost(): string
{
    return parse_url(config('app.url'), PHP_URL_HOST).(($p = parse_url(config('app.url'), PHP_URL_PORT)) ? ':'.$p : '');
}

function credentialFor(array $peer, ?int $iat = null, ?int $exp = null, ?string $host = null, ?string $secret = null): string
{
    $iat ??= time();

    return RequestSigner::mintBearer($peer['uuid'], $host ?? credentialHost(), $iat, $exp ?? $iat + 86400, $secret ?? $peer['secret']);
}

function clientPeer(array $grants = ['print.share'], string $kind = 'rstation'): array
{
    $peer = fedPeer($kind);
    fedEnroll($peer, $grants, (KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']))->id);

    return $peer;
}

test('a key-signed credential authenticates a device like a signed request would', function () {
    $peer = clientPeer();

    $this->withToken(credentialFor($peer))->postJson('/api/devices/heartbeat', [])->assertOk();
    expect(Trust::where('peer_uuid', $peer['uuid'])->first()->last_seen_at)->not->toBeNull();
});

test('a forged, mis-addressed or unknown credential is a 401', function () {
    $peer = clientPeer();
    $other = fedPeer('rstation');

    $this->withToken(credentialFor($peer, secret: $other['secret']))->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    $this->withToken(credentialFor($peer, host: 'elsewhere.example.org'))->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    $this->withToken(credentialFor($other))->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    $this->withToken('RIK-garbage')->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    // a credential good for a month is allowed, a longer-lived one is not what any client mints
    $this->withToken(credentialFor($peer, exp: time() + 40 * 86400))->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    $this->withToken(credentialFor($peer, exp: time() + 30 * 86400))->postJson('/api/devices/heartbeat', [])->assertOk();
});

test('an expired credential, or one from a wrong clock, is a 419 the Pi platform will not wipe itself over', function () {
    $peer = clientPeer();

    $this->withToken(credentialFor($peer, time() - 7200, time() - 3600))->postJson('/api/devices/heartbeat', [])
        ->assertStatus(419)->assertHeader('X-RI-Server-Time')->assertJsonPath('code', 'credential_expired');
    $this->withToken(credentialFor($peer, time() + 7200, time() + 90000))->postJson('/api/devices/heartbeat', [])->assertStatus(419);
    // a tiny drift is tolerated
    $this->withToken(credentialFor($peer, time() + 60, time() + 90000))->postJson('/api/devices/heartbeat', [])->assertOk();
});

test('a revoked trust, and an instance peer, cannot use a credential', function () {
    $peer = clientPeer();
    $instance = fedPeer('instance');
    fedEnroll($instance, ['feedback.send']);

    $this->withToken(credentialFor($instance))->getJson('/api/federation/v1/grants')->assertUnauthorized();

    TrustService::revoke(Trust::where('peer_uuid', $peer['uuid'])->first(), null);
    $this->withToken(credentialFor($peer))->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
});

test('a credential cannot earn a session, and print jobs still need the print.share grant', function () {
    $peer = clientPeer();
    $this->withToken(credentialFor($peer))->postJson('/api/federation/v1/session', [])->assertUnauthorized();

    $this->withToken(credentialFor($peer))->getJson('/api/devices/print-jobs')->assertOk();
    $noPrint = clientPeer([]);
    $this->withToken(credentialFor($noPrint))->getJson('/api/devices/print-jobs')->assertForbidden();
});

/** A device as the old pairing left it: a record with a bearer token and no trust. */
function legacyDevice(string $token = 'legacy-token', array $attrs = []): ManagedDevice
{
    $device = (new ManagedDevice(array_merge(['name' => 'Old Pi', 'update_channel' => 'stable'], $attrs)))->forceFill([
        'device_uuid' => 'legacy-'.bin2hex(random_bytes(4)),
        'token_hash' => ManagedDevice::hashToken($token),
        'kiosk_location_id' => (KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']))->id,
    ]);
    $device->save();

    return $device;
}

function upgradeBody(ManagedDevice $device, array $peer): array
{
    return [
        'public_key' => $peer['public_key'],
        'signature' => RequestSigner::sign(FederationController::upgradeMessage($device->device_uuid, $peer['public_key']), $peer['secret']),
    ];
}

test('an already-paired device upgrades in place while the legacy flag is on, and its old token dies', function () {
    config(['federation.legacy_device_tokens' => true]);
    $device = legacyDevice();
    $peer = fedPeer('rstation');
    $peer['uuid'] = $device->device_uuid;

    $this->withToken('legacy-token')->postJson('/api/federation/v1/upgrade-device', upgradeBody($device, $peer))
        ->assertCreated()->assertJsonPath('permissions', ['print.share']);

    $trust = Trust::where('peer_uuid', $device->device_uuid)->first();
    expect($trust->kind)->toBe('rstation')->and($trust->auth_method)->toBe('device_upgrade')
        ->and($trust->approved_by_label)->toBe('Upgraded from an earlier pairing')
        ->and($device->fresh()->trust_id)->toBe($trust->id)->and($device->fresh()->token_hash)->toBeNull();

    $this->withToken('legacy-token')->postJson('/api/devices/heartbeat', [])->assertUnauthorized();
    $this->withToken(RequestSigner::mintBearer($device->device_uuid, credentialHost(), time(), time() + 3600, $peer['secret']))
        ->postJson('/api/devices/heartbeat', [])->assertOk();
    // one-way: the same call cannot be repeated with the retired token
    $this->withToken('legacy-token')->postJson('/api/federation/v1/upgrade-device', upgradeBody($device, $peer))->assertUnauthorized();
});

test('upgrading needs the legacy flag, a valid token and proof of the new key', function () {
    $device = legacyDevice();
    $peer = fedPeer('rstation');
    $peer['uuid'] = $device->device_uuid;

    $this->withToken('legacy-token')->postJson('/api/federation/v1/upgrade-device', upgradeBody($device, $peer))->assertUnauthorized();

    config(['federation.legacy_device_tokens' => true]);
    $this->withToken('wrong')->postJson('/api/federation/v1/upgrade-device', upgradeBody($device, $peer))->assertUnauthorized();
    $bad = upgradeBody($device, $peer);
    $bad['signature'] = RequestSigner::sign('something else', $peer['secret']);
    $this->withToken('legacy-token')->postJson('/api/federation/v1/upgrade-device', $bad)->assertStatus(422);
    expect(Trust::count())->toBe(0)->and($device->fresh()->token_hash)->not->toBeNull();
});

test('a desktop print server upgrades as a print client', function () {
    config(['federation.legacy_device_tokens' => true]);
    $device = legacyDevice('t2', ['kiosk_function' => 'print_station']);
    $device->forceFill(['platform' => 'windows'])->save();
    $peer = fedPeer('print_client');
    $peer['uuid'] = $device->device_uuid;

    $this->withToken('t2')->postJson('/api/federation/v1/upgrade-device', upgradeBody($device, $peer))->assertCreated();
    expect(Trust::where('peer_uuid', $device->device_uuid)->value('kind'))->toBe('print_client');
});
