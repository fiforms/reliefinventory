<?php

use App\Models\KioskLocation;
use App\Models\Trust;
use App\Models\TrustAuditLog;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;
use Illuminate\Support\Facades\Route;

test('the public identity document carries the key and fingerprint but never the private key', function () {
    $doc = $this->getJson('/.well-known/ri-instance.json')->assertOk()->json();
    expect($doc['public_key'])->toBe(InstanceIdentity::current()->public_key)
        ->and($doc['fingerprint'])->toBe(RequestSigner::fingerprint($doc['public_key']))
        ->and(json_encode($doc))->not->toContain(InstanceIdentity::current()->private_key);
});

test('a peer pairs with a code, proving it holds the key, and the code works once', function () {
    $peer = fedPeer();
    [$code] = TrustService::createPairingCode('instance', ['feedback.send', 'bogus.key'], null, null, null);

    $res = $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code))->assertCreated();
    expect($res->json('server.public_key'))->toBe(InstanceIdentity::current()->public_key)
        ->and($res->json('permissions'))->toBe(['feedback.send']);

    $trust = Trust::where('peer_uuid', $peer['uuid'])->first();
    expect($trust->status)->toBe('approved')->and($trust->auth_method)->toBe('pairing_code')
        ->and($trust->public_key)->toBe($peer['public_key'])
        ->and(TrustAuditLog::where('trust_id', $trust->id)->value('event'))->toBe('enrolled');

    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code))->assertStatus(422);
});

test('pairing refuses a bad proof of possession, a wrong kind and an expired code', function () {
    $peer = fedPeer();
    $other = fedPeer();
    [$code] = TrustService::createPairingCode('instance', [], null, null, null);

    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code, $other['secret']))->assertStatus(422);
    $this->postJson('/api/federation/v1/pair', fedPairBody(['kind' => 'rstation'] + $peer, $code))->assertStatus(422);

    $this->travel(20)->minutes();
    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code))->assertStatus(422);
    expect(Trust::count())->toBe(0);
});

test('a client pairing creates its managed device and revoking the trust revokes the device', function () {
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $peer = fedPeer('print_client');
    $trust = fedEnroll($peer, ['print.share'], $location->id);

    $device = $trust->managedDevice;
    expect($device->device_uuid)->toBe($peer['uuid'])->and($device->kiosk_location_id)->toBe($location->id)
        ->and($device->trust_id)->toBe($trust->id);

    TrustService::revoke($trust, null);
    expect($device->fresh()->revoked_at)->not->toBeNull()->and($trust->fresh()->status)->toBe('revoked');
});

test('re-pairing with a different key replaces it but is audited as a key change', function () {
    $peer = fedPeer();
    $first = fedEnroll($peer);
    $second = fedEnroll(['public_key' => fedPeer()['public_key']] + $peer);

    expect($second->id)->toBe($first->id)->and($second->public_key)->not->toBe($peer['public_key'])
        ->and(TrustAuditLog::where('event', 'key_changed')->count())->toBe(1);
});

test('signed requests are accepted, and tampering, replay, skew and revocation are refused', function () {
    $peer = fedPeer();
    $trust = fedEnroll($peer, ['feedback.send']);
    $uri = '/api/federation/v1/grants';

    fedCall($this, $peer, 'GET', $uri)->assertOk()->assertJson(['permissions' => ['feedback.send']]);

    // wrong path signed
    fedCall($this, $peer, 'GET', $uri, '', null, null, '/api/federation/v1/session')->assertUnauthorized();
    // an unknown peer
    $stranger = fedPeer();
    fedCall($this, $stranger, 'GET', $uri)->assertUnauthorized();
    // replayed nonce
    fedCall($this, $peer, 'GET', $uri, '', null, 'fixed-nonce')->assertOk();
    fedCall($this, $peer, 'GET', $uri, '', null, 'fixed-nonce')->assertUnauthorized();
    // clock skew is a 400 that carries the server time, never a 401
    $this->getJson($uri, fedHeaders($peer, 'GET', $uri, '', time() - 3600))
        ->assertStatus(400)->assertHeader('X-RI-Server-Time');

    TrustService::revoke($trust, null);
    fedCall($this, $peer, 'GET', $uri)->assertUnauthorized();
});

test('a signed body is bound to the signature', function () {
    $peer = fedPeer();
    fedEnroll($peer);
    $uri = '/api/federation/v1/session';
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach (fedHeaders($peer, 'POST', $uri, '{"a":1}') as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }

    $this->call('POST', $uri, [], [], [], $server, '{"a":2}')->assertUnauthorized();
});

test('a session token replaces per-request signing until the trust is revoked or re-approved', function () {
    $peer = fedPeer();
    $trust = fedEnroll($peer, ['feedback.send']);
    $uri = '/api/federation/v1/session';

    $token = fedCall($this, $peer, 'POST', $uri, '{}')->assertOk()->json('session_token');
    expect($token)->toStartWith('RIS-');

    $auth = ['Authorization' => 'Bearer '.$token];
    $this->getJson('/api/federation/v1/grants', $auth)->assertOk();
    // the session endpoint itself always wants a signature
    $this->postJson($uri, [], $auth)->assertUnauthorized();

    TrustService::setInboundGrants($trust, [], null);
    $this->getJson('/api/federation/v1/grants', $auth)->assertOk()->assertJson(['permissions' => []]);

    TrustService::revoke($trust, null);
    $this->getJson('/api/federation/v1/grants', $auth)->assertUnauthorized();
    // re-pairing re-approves the trust, but the old session does not come back
    $trust2 = fedEnroll($peer);
    $this->travel(2)->seconds();
    expect($trust2->id)->toBe($trust->id);
    $this->getJson('/api/federation/v1/grants', $auth)->assertUnauthorized();
});

test('a route can require an inbound grant', function () {
    Route::get('/api/federation/test-needs-feedback', fn () => 'ok')->middleware('trust-auth:feedback.send');
    $withGrant = fedPeer();
    $without = fedPeer();
    fedEnroll($withGrant, ['feedback.send']);
    fedEnroll($without, ['print.share']);
    $uri = '/api/federation/test-needs-feedback';

    fedCall($this, $withGrant, 'GET', $uri)->assertOk();
    fedCall($this, $without, 'GET', $uri)->assertForbidden();
});

test('the Trust Console is for manage-trusts only and issues a code, edits grants and revokes', function () {
    $this->actingAs(userWithPermissions('manage-people'))->getJson('/json/trusts')->assertForbidden();

    $admin = userWithPermissions('manage-trusts');
    $this->actingAs($admin);
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);

    $this->postJson('/json/trusts/pairing-codes', ['kind' => 'print_client'])->assertStatus(422); // needs a location
    $code = $this->postJson('/json/trusts/pairing-codes', [
        'kind' => 'print_client', 'kiosk_location_id' => $location->id, 'grants' => ['print.share'],
    ])->assertCreated()->json('code');
    expect($code)->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');

    $peer = fedPeer('print_client');
    $this->postJson('/api/federation/v1/pair', fedPairBody($peer, $code))->assertCreated();
    $trust = Trust::where('peer_uuid', $peer['uuid'])->first();
    expect($trust->approved_by_person_id)->toBe($admin->id);

    $listed = $this->getJson('/json/trusts')->assertOk()->json();
    expect($listed['trusts'][0]['inbound'])->toBe(['print.share'])
        ->and($listed['trusts'][0]['approved_by'])->not->toBeNull()
        ->and(json_encode($listed))->not->toContain(InstanceIdentity::current()->private_key);

    $this->putJson("/json/trusts/{$trust->id}/grants", ['inbound' => ['feedback.send', 'nonsense']])->assertStatus(422);
    $this->putJson("/json/trusts/{$trust->id}/grants", ['inbound' => ['feedback.send']])
        ->assertOk()->assertJsonPath('record.inbound', ['feedback.send']);
    $this->postJson("/json/trusts/{$trust->id}/revoke")->assertOk()->assertJsonPath('record.status', 'revoked');

    $this->postJson('/json/trusts/identity/regenerate', [])->assertStatus(422);
    $old = InstanceIdentity::current()->public_key;
    $this->postJson('/json/trusts/identity/regenerate', ['confirm' => 'REGENERATE'])->assertOk();
    expect(InstanceIdentity::current()->public_key)->not->toBe($old);
});

test('a lost identity refuses federation calls until it is regenerated', function () {
    InstanceIdentity::current();
    DB::table('federation_identities')->update(['private_key' => null]);
    $this->getJson('/api/federation/v1/grants')->assertStatus(503);
    $this->postJson('/api/federation/v1/pair', ['code' => 'x', 'kind' => 'instance', 'uuid' => 'u', 'name' => 'n', 'base_url' => 'https://x.example.test', 'public_key' => str_repeat('a', 43), 'signature' => 's'])
        ->assertStatus(503);
});
