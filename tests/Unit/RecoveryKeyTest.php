<?php

// The recovery key pair (App\Support\RecoveryKey): a private key that survives being printed and typed back, and a sealed box that only it opens.

use App\Support\RecoveryKey;

test('a generated pair round-trips: the printed key parses back to a secret whose public half is the one kept', function () {
    $pair = RecoveryKey::generate();

    expect($pair['private'])->toMatch('/^RK1(-[0-9A-HJKMNP-TV-Z]{5}){11}$/')
        ->and(RecoveryKey::isPublicKey($pair['public']))->toBeTrue()
        ->and(strlen($pair['public']))->toBe(43)
        ->and($pair['fingerprint'])->toMatch('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/')
        ->and(RecoveryKey::publicFor(RecoveryKey::parse($pair['private'])))->toBe($pair['public'])
        ->and(RecoveryKey::fingerprint($pair['public']))->toBe($pair['fingerprint']);
});

test('two pairs are different, and the printed key is not the public one', function () {
    $a = RecoveryKey::generate();
    $b = RecoveryKey::generate();

    expect($a['private'])->not->toBe($b['private'])->and($a['public'])->not->toBe($b['public'])
        ->and($a['private'])->not->toContain($a['public']);
});

test('the key is read however it was typed, pasted or saved', function () {
    $pair = RecoveryKey::generate();
    $secret = RecoveryKey::parse($pair['private']);

    expect(RecoveryKey::parse(strtolower($pair['private'])))->toBe($secret)
        ->and(RecoveryKey::parse(str_replace('-', ' ', $pair['private'])))->toBe($secret)
        ->and(RecoveryKey::parse(str_replace('-', '', $pair['private'])))->toBe($secret)
        ->and(RecoveryKey::parse("  \n{$pair['private']}\n"))->toBe($secret)
        // the downloaded file has instructions around the key
        ->and(RecoveryKey::parse("RELIEF INVENTORY — RECOVERY KEY (x)\n\nRecovery key:\n  {$pair['private']}\n\nWHAT THIS IS\nKeep it safe.\n"))->toBe($secret);
});

test('the look-alike letters are read as the digits they stand for', function () {
    // Crockford base32 has no 0/O/1/I/L: a person reading a printout may type any of them.
    $pair = RecoveryKey::generate();
    $secret = RecoveryKey::parse($pair['private']);
    $confused = 'RK1'.strtr(substr($pair['private'], 3), ['0' => 'O', '1' => 'I']);

    expect(RecoveryKey::parse($confused))->toBe($secret);
    expect(RecoveryKey::parse('RK1'.strtr(substr($pair['private'], 3), ['1' => 'L'])))->toBe($secret);
});

test('a typing mistake is caught by the check characters, before anything is tried', function () {
    $pair = RecoveryKey::generate();
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    // Change each of a spread of positions to a different valid character: every one must be refused, none may open anything.
    foreach ([4, 10, 20, 33, 50, 60, 68] as $at) {
        $bad = $pair['private'];
        $bad[$at] = $bad[$at] === '-' ? '-' : $alphabet[(strpos($alphabet, $bad[$at]) + 1) % 32];
        if ($bad === $pair['private']) {
            continue;
        }
        expect(fn () => RecoveryKey::parse($bad))->toThrow(RuntimeException::class);
    }
    expect(fn () => RecoveryKey::parse(substr($pair['private'], 0, -3)))->toThrow(RuntimeException::class, 'wrong length')
        ->and(fn () => RecoveryKey::parse('hello'))->toThrow(RuntimeException::class, 'RK1')
        ->and(fn () => RecoveryKey::parse(''))->toThrow(RuntimeException::class);
});

test('something sealed to the public key opens only with its private key', function () {
    $pair = RecoveryKey::generate();
    $other = RecoveryKey::generate();
    $sealed = RecoveryKey::seal('{"keys":{"AAAAAAAA":"base64:secret-material"}}', $pair['public']);

    expect($sealed)->not->toContain('secret-material')
        ->and(RecoveryKey::open($sealed, RecoveryKey::parse($pair['private'])))->toBe('{"keys":{"AAAAAAAA":"base64:secret-material"}}')
        ->and(fn () => RecoveryKey::open($sealed, RecoveryKey::parse($other['private'])))->toThrow(RuntimeException::class, 'not the one')
        // sealing is randomised: the same message sealed twice differs, so a zip gives nothing away about which warehouses share a key
        ->and(RecoveryKey::seal('x', $pair['public']))->not->toBe(RecoveryKey::seal('x', $pair['public']));
});

test('only a well-formed public key is accepted', function () {
    $pair = RecoveryKey::generate();

    expect(RecoveryKey::isPublicKey($pair['public']))->toBeTrue()
        ->and(RecoveryKey::isPublicKey(''))->toBeFalse()
        ->and(RecoveryKey::isPublicKey('too-short'))->toBeFalse()
        ->and(RecoveryKey::isPublicKey($pair['public'].'A'))->toBeFalse()
        ->and(RecoveryKey::isPublicKey($pair['private']))->toBeFalse()
        ->and(fn () => RecoveryKey::seal('x', 'not-a-key'))->toThrow(RuntimeException::class);
});
