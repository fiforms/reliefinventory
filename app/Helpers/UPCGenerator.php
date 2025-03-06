<?php

namespace App\Helpers;

class UPCGenerator
{
    /**
     * Generate a valid UPC from a given 5-digit item number.
     *
     * @param string $itemNumber A 5-digit numeric string.
     * @return string A valid 12-digit UPC.
     */
    public static function makeUPCFromItemNumber(string $itemNumber): string
    {
        // Ensure the item number is exactly 5 digits
        $itemNumber = str_pad($itemNumber, 5, '0', STR_PAD_LEFT);

        // Construct the base UPC (prefix "2" + item number + padding)
        $upcBase = '2' . $itemNumber;
        $upcBase = str_pad($upcBase, 11, '0', STR_PAD_RIGHT); // Ensure 11 digits

        // Calculate the check digit
        $checkDigit = self::calculateUPCCheckDigit($upcBase);

        return $upcBase . $checkDigit; // Full 12-digit UPC
    }

    /**
     * Calculate the UPC check digit using the standard UPC-A formula.
     *
     * @param string $upcBase The first 11 digits of a UPC.
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
