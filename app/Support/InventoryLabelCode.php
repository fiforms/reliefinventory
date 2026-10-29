<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use App\Models\PalletStockAssignment;

/**
 * The number on an inventory label: "ACS:<family>-<variant>:<assignment id>", e.g. ACS:1234-01:5678. The id is what
 * identifies the label (one pallet_stock_assignments row = item + qty + pallet); the ACS item number in the middle is
 * there for people and as a cross-check, written in full (4-digit family, 2-digit variant) rather than the display
 * form that hides "-00", so it never changes shape. Everything is Code 128 set B safe.
 */
class InventoryLabelCode
{
    public const PREFIX = 'ACS';

    public static function make(PalletStockAssignment $assignment): string
    {
        $type = $assignment->item?->itemtype;

        return self::PREFIX.':'.($type?->family ?: '0000').'-'.($type?->variant ?: '00').':'.$assignment->id;
    }

    /** The assignment id in a scanned inventory label number, or null when the text isn't one. */
    public static function parse(string $code): ?int
    {
        return preg_match('/^'.self::PREFIX.':\d{4}-\d{2}:([1-9]\d{0,17})$/', trim($code), $m) ? (int) $m[1] : null;
    }
}
