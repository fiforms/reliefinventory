<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Helpers;

/**
 * Barcode (GTIN) helpers for the UPC lookup flow. Every code is normalized to
 * 14 digits (GTIN-14), the one form that can hold UPC-A (12), EAN-13 and
 * EAN-8 by left-padding with zeros, so "012345678905" scanned as UPC-A and
 * "0012345678905" scanned as EAN-13 are the same key.
 *
 * Unrelated to UPCGenerator, which *makes* our own item labels.
 */
class Gtin
{
    /** Scanned lengths worth treating as a barcode (UPC-E expanded by the scanner is 12). */
    public const LENGTHS = [8, 12, 13, 14];

    /** Digits only, or null when the input isn't a plausible barcode length. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);
        if ($digits === '' || ! in_array(strlen($digits), self::LENGTHS, true)) {
            return null;
        }

        return str_pad($digits, 14, '0', STR_PAD_LEFT);
    }

    /** True when the text a user typed/scanned is all digits at a barcode length. */
    public static function looksLikeBarcode(?string $input): bool
    {
        $input = trim((string) $input);

        return $input !== '' && ctype_digit($input) && in_array(strlen($input), self::LENGTHS, true);
    }

    public static function checkDigit(string $thirteenDigits): int
    {
        $sum = 0;
        // weights 3,1,3,... counted from the digit next to the check digit
        for ($i = 0, $n = strlen($thirteenDigits); $i < $n; $i++) {
            $sum += (int) $thirteenDigits[$n - 1 - $i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValid(string $gtin14): bool
    {
        return strlen($gtin14) === 14 && ctype_digit($gtin14)
            && self::checkDigit(substr($gtin14, 0, 13)) === (int) $gtin14[13];
    }

    /**
     * A 14-digit code whose packaging-level (first) digit is 1-8 is a shipping
     * carton/case (ITF-14). Anything shorter, or with a 0 indicator, is a
     * single sellable unit — a retail UPC that is really a multipack can't be
     * told apart from the number alone, so a person confirms that case.
     */
    public static function kind(string $gtin14): string
    {
        return $gtin14[0] >= '1' && $gtin14[0] <= '8' ? 'carton' : 'each';
    }

    /**
     * For a carton code, the single-unit code it most likely wraps: GS1's
     * convention is that a one-item case reuses the item's own number under a
     * different indicator digit. Only a guess (brands assign multipacks new
     * numbers) — callers must have a person confirm it.
     */
    public static function guessEachCode(string $cartonGtin14): string
    {
        $body = '0'.substr($cartonGtin14, 1, 12);

        return $body.self::checkDigit($body);
    }

    /**
     * Every spelling under which an `items.upc` value for this code might be
     * stored (UPC-A 12, EAN-13, GTIN-14, EAN-8), for matching hand-keyed UPCs.
     *
     * @return string[]
     */
    public static function variants(string $gtin14): array
    {
        $stripped = ltrim($gtin14, '0');
        $out = [];
        foreach (self::LENGTHS as $length) {
            if (strlen($stripped) <= $length) {
                $out[] = str_pad($stripped, $length, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($out));
    }
}
