<?php

use App\Support\PalletKind;

test('each kind has its own initial status', function () {
    expect(PalletKind::initialStatus('R'))->toBe('received')
        ->and(PalletKind::initialStatus('W'))->toBe('sealed')
        ->and(PalletKind::initialStatus('S'))->toBe('building')
        ->and(PalletKind::initialStatus('H'))->toBe('filling')
        ->and(PalletKind::initialStatus('Q'))->toBe('held');
});

test('a status only valid for one kind is rejected for another', function () {
    expect(PalletKind::isValidStatus('R', 'sorting'))->toBeTrue()
        ->and(PalletKind::isValidStatus('W', 'sorting'))->toBeFalse()
        ->and(PalletKind::isValidStatus('Q', 'shipped'))->toBeFalse();
});

test('"missing" is valid for every kind', function () {
    foreach (['R', 'W', 'S', 'H', 'Q'] as $kind) {
        expect(PalletKind::isValidStatus($kind, 'missing'))->toBeTrue();
    }
});

test('quarantine branches to two different terminal statuses', function () {
    expect(PalletKind::isTerminal('Q', 'released'))->toBeTrue()
        ->and(PalletKind::isTerminal('Q', 'condemned'))->toBeTrue()
        ->and(PalletKind::isTerminal('Q', 'held'))->toBeFalse();
});

test('receiving and warehouse both terminate at empty', function () {
    expect(PalletKind::isTerminal('R', 'empty'))->toBeTrue()
        ->and(PalletKind::isTerminal('W', 'empty'))->toBeTrue()
        ->and(PalletKind::isTerminal('R', 'sorting'))->toBeFalse();
});
