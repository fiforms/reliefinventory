<?php

use App\Models\ItemType;

test('parses a hyphenated number, padding both sides to canonical width', function () {
    expect(ItemType::parseNumber('318-90'))->toBe(['0318', '90'])
        ->and(ItemType::parseNumber('42-1'))->toBe(['0042', '01']);
});

test('parses a canonical 4-digit undashed number as a family with no variant', function () {
    expect(ItemType::parseNumber('0318'))->toBe(['0318', null])
        ->and(ItemType::parseNumber('7777'))->toBe(['7777', null]);
});

test('parses a canonical 6-digit undashed number as a 4-digit family plus 2-digit variant', function () {
    expect(ItemType::parseNumber('031890'))->toBe(['0318', '90'])
        ->and(ItemType::parseNumber('340101'))->toBe(['3401', '01']);
});

test('does not guess a split for shorthand digits of ambiguous length', function () {
    // "4201" could mean family 0042 + variant 01, or a real 4-digit family
    // in its own right — parseNumber must not silently pick one. Resolving
    // shorthand is a search-and-confirm UI concern, not this function's job.
    expect(ItemType::parseNumber('4201'))->toBe(['4201', null]);
});
