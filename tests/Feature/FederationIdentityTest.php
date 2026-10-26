<?php

use App\Models\FederationIdentity;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\RequestSigner;
use Illuminate\Support\Facades\DB;

function signingVectors(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/federation-signing-vectors.json')), true);
}

test('the shared signing vectors reproduce byte for byte', function () {
    $fixture = signingVectors();
    $kp = sodium_crypto_sign_seed_keypair(RequestSigner::unb64($fixture['seed_b64']));
    expect(RequestSigner::b64(sodium_crypto_sign_publickey($kp)))->toBe($fixture['public_key'])
        ->and(RequestSigner::fingerprint($fixture['public_key']))->toBe($fixture['fingerprint']);

    foreach ($fixture['vectors'] as $v) {
        $canonical = RequestSigner::canonical($v['method'], $v['path_and_query'], $v['host'], $v['timestamp'], $v['nonce'], $v['body']);
        expect($canonical)->toBe($v['canonical'])
            ->and(RequestSigner::sign($canonical, RequestSigner::b64(sodium_crypto_sign_secretkey($kp))))->toBe($v['signature'])
            ->and(RequestSigner::verify($canonical, $v['signature'], $fixture['public_key']))->toBeTrue();
    }
});

test('a tampered request or wrong key fails verification', function () {
    $v = signingVectors()['vectors'][0];
    $pub = signingVectors()['public_key'];
    $tampered = RequestSigner::canonical('POST', '/federation/v1/session?a=2', $v['host'], $v['timestamp'], $v['nonce'], $v['body']);
    expect(RequestSigner::verify($tampered, $v['signature'], $pub))->toBeFalse()
        ->and(RequestSigner::verify($v['canonical'], $v['signature'], RequestSigner::b64(random_bytes(32))))->toBeFalse()
        ->and(RequestSigner::verify($v['canonical'], 'garbage', $pub))->toBeFalse();
});

test('the instance identity is created once and its private key is encrypted at rest', function () {
    $a = InstanceIdentity::current();
    $b = InstanceIdentity::current();
    expect($a->id)->toBe($b->id)->and(FederationIdentity::count())->toBe(1);

    $raw = DB::table('federation_identities')->value('private_key');
    expect($raw)->not->toBe($a->private_key)->and($a->toArray())->not->toHaveKey('private_key');

    $canonical = RequestSigner::canonical('GET', '/x', 'h', 1, 'n');
    expect(RequestSigner::verify($canonical, InstanceIdentity::sign($canonical), $a->public_key))->toBeTrue();
});

test('a blanked private key reads as a lost identity until it is regenerated', function () {
    $identity = InstanceIdentity::current();
    $old = $identity->public_key;
    DB::table('federation_identities')->update(['private_key' => null]);

    expect(InstanceIdentity::isLost())->toBeTrue()
        ->and(fn () => InstanceIdentity::sign('x'))->toThrow(RuntimeException::class);

    $new = InstanceIdentity::regenerate();
    expect(InstanceIdentity::isLost())->toBeFalse()
        ->and($new->public_key)->not->toBe($old)
        ->and($new->uuid)->toBe($identity->uuid);
});

test('the shared pairing, upgrade and bearer-credential vectors reproduce', function () {
    $fixture = signingVectors();
    $secret = RequestSigner::b64(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(RequestSigner::unb64($fixture['seed_b64']))));

    foreach ($fixture['pairing_vectors'] as $v) {
        expect(\App\Http\Controllers\FederationController::pairingMessage($v['code'], $fixture['public_key'], $v['uuid']))->toBe($v['message'])
            ->and(RequestSigner::sign($v['message'], $secret))->toBe($v['signature']);
    }
    foreach ($fixture['upgrade_vectors'] as $v) {
        expect(\App\Http\Controllers\FederationController::upgradeMessage($v['uuid'], $fixture['public_key']))->toBe($v['message'])
            ->and(RequestSigner::sign($v['message'], $secret))->toBe($v['signature']);
    }
    foreach ($fixture['bearer_vectors'] as $v) {
        expect(RequestSigner::bearerMessage($v['uuid'], $v['host'], $v['iat'], $v['exp']))->toBe($v['message'])
            ->and(RequestSigner::mintBearer($v['uuid'], $v['host'], $v['iat'], $v['exp'], $secret))->toBe($v['token'])
            ->and(RequestSigner::parseBearer($v['token']))->toMatchArray(['uuid' => $v['uuid'], 'iat' => $v['iat'], 'exp' => $v['exp']]);
    }
    expect(RequestSigner::parseBearer('RIK-nonsense'))->toBeNull()->and(RequestSigner::parseBearer('Bearer x'))->toBeNull();
});
