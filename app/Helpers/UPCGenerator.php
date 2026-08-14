<?php

namespace App\Helpers;

class UPCGenerator
{
    /**
     * Generate a valid UPC-A from a family/variant item number.
     *
     * The 10-digit data field is family (zero-padded to 4 digits) + variant
     * (zero-padded to 2 digits, "00" when there is no variant), then
     * left-padded with zeros to fill all 10 digits. That leftover leading
     * space is deliberate: a location code could be written into those
     * digits later without changing the field width or check-digit math.
     *
     * @param  string  $family  3 or 4 digit family number (e.g. "318", "1100").
     * @param  string|null  $variant  2-digit variant, or null for the bare item.
     * @return string A valid 12-digit UPC-A.
     */
    public static function makeUPC(string $family, ?string $variant = null): string
    {
        $familyPadded = str_pad($family, 4, '0', STR_PAD_LEFT);
        $variantPadded = str_pad($variant ?? '00', 2, '0', STR_PAD_LEFT);

        $itemCode = str_pad($familyPadded.$variantPadded, 10, '0', STR_PAD_LEFT);

        // "2" = GS1 in-store/internal-use number system prefix.
        $upcBase = '2'.$itemCode; // 11 digits: 1 prefix + 10 data

        $checkDigit = self::calculateUPCCheckDigit($upcBase);

        return $upcBase.$checkDigit; // 12-digit UPC-A
    }

    /**
     * Calculate the UPC check digit using the standard UPC-A formula.
     *
     * @param  string  $upcBase  The first 11 digits of a UPC.
     * @return int The calculated check digit.
     */
    private static function calculateUPCCheckDigit(string $upcBase): int
    {
        $digits = str_split($upcBase);
        $sum = 0;

        // Apply the UPC-A check digit calculation
        foreach ($digits as $i => $digit) {
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }

        $modulo = $sum % 10;

        return ($modulo === 0) ? 0 : (10 - $modulo);
    }
}
