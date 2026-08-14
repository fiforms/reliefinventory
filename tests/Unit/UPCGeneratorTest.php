<?php

use App\Helpers\UPCGenerator;

test('generates a 12-digit UPC-A with the family/variant reserved in the leading digits', function () {
    // Hand-verified: family "318" -> "0318", variant null -> "00",
    // data field "0000031800", prefix "2" -> check digit 0.
    expect(UPCGenerator::makeUPC('318'))->toBe('200000318000');
});

test('a 4-digit family still reserves the leading digits for a future location prefix', function () {
    // "2" prefix + 4 reserved digits + "7777" family + "00" variant + check digit
    expect(UPCGenerator::makeUPC('7777'))->toHaveLength(12)
        ->and(UPCGenerator::makeUPC('7777'))->toStartWith('2000077770');
});

test('bare (null variant) and an explicit "00" variant intentionally produce the same UPC', function () {
    // Safe only because the numbering rules guarantee a family never has
    // both a bare item and a real "-00" variant at the same time (404
    // newborn diapers is the one documented exception, and 404 itself is
    // never bare/orderable) — see UPCGenerator::makeUPC's doc comment.
    expect(UPCGenerator::makeUPC('404', '00'))->toBe(UPCGenerator::makeUPC('404'));
});

test('different variants of the same family produce different UPCs', function () {
    expect(UPCGenerator::makeUPC('318', '90'))->not->toBe(UPCGenerator::makeUPC('318', '91'));
});

test('the leading digits of the 10-digit data field are reserved (zero) for a future location prefix', function () {
    $upc = UPCGenerator::makeUPC('318', '90');
    // "2" prefix + 4 reserved digits + "0318" + "90" + check digit
    expect(substr($upc, 1, 4))->toBe('0000');
});
