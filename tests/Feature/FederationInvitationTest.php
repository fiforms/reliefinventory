<?php

use App\Models\KioskLocation;
use App\Models\Trust;
use App\Services\Federation\DomainVerifier;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\Invitation;
use App\Services\Federation\PeerUrl;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['federation.allow_private_hosts' => true]);
    InstanceIdentity::current()->update(['base_url' => 'https://b.example.test', 'name' => 'Site B']);
});

test('an invitation carries this address, a working code and the key fingerprint', function () {
    $admin = userWithPermissions('manage-trusts');
    $res = $this->actingAs($admin)->postJson('/json/trusts/invitations', ['grants' => ['feedback.send']])->assertCreated();
    $invite = Invitation::parse($res->json('connection_code'));

    expect($invite['url'])->toBe('https://b.example.test')
        ->and($invite['fingerprint'])->toBe(RequestSigner::fingerprint(InstanceIdentity::current()->public_key));

    // an instance peer must say where it lives
    $peer = fedPeer('instance');
    $body = fedPairBody($peer, $invite['code']);
    $this->postJson('/api/federation/v1/pair', ['base_url' => null] + $body)->assertStatus(422)->assertJsonValidationErrors('base_url');
    $this->postJson('/api/federation/v1/pair', $body)->assertCreated();
    expect(Trust::where('peer_uuid', $peer['uuid'])->first()->base_url)->toBe('https://peer.example.test');
});

test('an invitation needs a public address on this instance', function () {
    InstanceIdentity::current()->update(['base_url' => null]);
    $this->actingAs(userWithPermissions('manage-trusts'))->postJson('/json/trusts/invitations', [])->assertStatus(422);
});

test('accepting an invitation verifies the fingerprint, pairs, and records a mirrored, domain-verified trust', function () {
    $remote = fakeRemote();
    serveRemote($remote);
    $admin = userWithPermissions('manage-trusts');
    $code = Invitation::make('https://a.example.test', 'ABCDE-FGHJK', RequestSigner::fingerprint($remote['public_key']));

    $res = $this->actingAs($admin)->postJson('/json/trusts/accept-invitation', [
        'connection_code' => $code, 'grants' => ['print.share'],
    ])->assertCreated();

    expect($remote['pair_body']['proof_ok'])->toBeTrue()
        ->and($remote['pair_body']['uuid'])->toBe(InstanceIdentity::current()->uuid)
        ->and($remote['pair_body']['base_url'])->toBe('https://b.example.test');

    $trust = Trust::where('peer_uuid', $remote['uuid'])->first();
    expect($trust->public_key)->toBe($remote['public_key'])
        ->and($trust->auth_method)->toBe('domain_verified')
        ->and($trust->domain_verified_at)->not->toBeNull()
        ->and($trust->approved_by_person_id)->toBe($admin->id)
        ->and($trust->inboundPermissions())->toBe(['print.share'])
        ->and($trust->grants()->where('direction', 'outbound')->pluck('permission')->all())->toBe(['feedback.send'])
        ->and($res->json('warning'))->toBeNull();
});

test('a connection code whose fingerprint does not match the answering instance pairs nothing', function () {
    $remote = fakeRemote();
    serveRemote($remote);
    $code = Invitation::make('https://a.example.test', 'ABCDE-FGHJK', RequestSigner::fingerprint(fedPeer()['public_key']));

    $this->actingAs(userWithPermissions('manage-trusts'))
        ->postJson('/json/trusts/accept-invitation', ['connection_code' => $code])->assertStatus(422);

    Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/federation/v1/pair'));
    expect(Trust::count())->toBe(0);
});

test('a refused code records nothing', function () {
    $remote = fakeRemote(['pair_status' => 422]);
    serveRemote($remote);
    $code = Invitation::make('https://a.example.test', 'ABCDE-FGHJK', RequestSigner::fingerprint($remote['public_key']));

    $this->actingAs(userWithPermissions('manage-trusts'))
        ->postJson('/json/trusts/accept-invitation', ['connection_code' => $code])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'refused'));
    expect($remote['pair_body']['proof_ok'])->toBeTrue()->and(Trust::count())->toBe(0);
});

test('a pair answer naming a different identity than the one published records nothing', function () {
    $other = fedPeer();
    $remote = fakeRemote();
    $remote['pair_identity'] = ['uuid' => $other['uuid'], 'public_key' => $other['public_key']];
    serveRemote($remote);
    $code = Invitation::make('https://a.example.test', 'ABCDE-FGHJK', RequestSigner::fingerprint($remote['public_key']));

    $this->actingAs(userWithPermissions('manage-trusts'))
        ->postJson('/json/trusts/accept-invitation', ['connection_code' => $code])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'different identity'));
    expect(Trust::count())->toBe(0);
});

test('a connection code that is not one is rejected', function () {
    $this->actingAs(userWithPermissions('manage-trusts'))
        ->postJson('/json/trusts/accept-invitation', ['connection_code' => 'nonsense'])->assertStatus(422);
});

test('outbound calls are signed with this instance key, then ride a session token', function () {
    $remote = fakeRemote();
    serveRemote($remote);
    $trust = TrustService::enrollInvited($remote['doc'], $remote['origin'], [], [], null);

    expect(TrustService::refreshOutbound($trust))->toBe(['feedback.send']);
    TrustService::refreshOutbound($trust);

    $kinds = collect($remote['seen'])->map(fn ($s) => $s[1].(isset($s[2]['Authorization']) ? ' bearer' : ' signed'))->all();
    expect($kinds)->toBe(['/api/federation/v1/session signed', '/api/federation/v1/grants bearer', '/api/federation/v1/grants bearer']);
});

test('domain verification pins the published key and clears when it changes', function () {
    $remote = fakeRemote();
    serveRemote($remote);
    $trust = TrustService::enrollInvited($remote['doc'], 'https://a.example.test', [], [], null);
    $trust->update(['domain_verified_at' => null, 'auth_method' => 'pairing_code']);

    expect(DomainVerifier::verify($trust)['ok'])->toBeTrue()
        ->and($trust->fresh()->auth_method)->toBe('domain_verified')
        ->and($trust->fresh()->domain_verified_at)->not->toBeNull();

    $remote['doc']['public_key'] = fedPeer()['public_key'];
    $result = DomainVerifier::verify($trust->fresh());
    expect($result['ok'])->toBeFalse()
        ->and($trust->fresh()->domain_verified_at)->toBeNull()
        ->and($trust->fresh()->auth_method)->toBe('pairing_code');
});

test('the console verifies a domain and refreshes outbound grants', function () {
    $remote = fakeRemote();
    serveRemote($remote);
    $trust = TrustService::enrollInvited($remote['doc'], 'https://a.example.test', [], [], null);
    $admin = userWithPermissions('manage-trusts');

    $this->actingAs($admin)->postJson("/json/trusts/{$trust->id}/verify-domain")->assertOk()->assertJsonPath('ok', true);
    $this->postJson("/json/trusts/{$trust->id}/refresh-grants")->assertOk()->assertJsonPath('record.outbound', ['feedback.send']);

    $client = fedEnroll(fedPeer('print_client'), [], KioskLocation::firstOrCreate(['name' => 'Site'])->id);
    $this->postJson("/json/trusts/{$client->id}/refresh-grants")->assertStatus(422);
});

test('peers are only contacted over https at public addresses unless an operator allows otherwise', function () {
    config(['federation.allow_private_hosts' => false, 'federation.allow_insecure_http' => false]);
    expect(fn () => PeerUrl::assertSafe('http://203.0.113.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PeerUrl::assertSafe('https://127.0.0.1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PeerUrl::assertSafe('https://10.1.2.3:8443'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PeerUrl::assertSafe('https://169.254.169.254'))->toThrow(InvalidArgumentException::class)
        ->and(PeerUrl::assertSafe('https://93.184.216.34'))->toBe('https://93.184.216.34');

    config(['federation.allow_private_hosts' => true, 'federation.allow_insecure_http' => true]);
    expect(PeerUrl::assertSafe('HTTP://Localhost:8001/path'))->toBe('http://localhost:8001');
});
