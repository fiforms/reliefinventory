<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * The five pallet kinds and their lifecycles, per the pallet-container-model
 * design. Status describes the container, not the process — this is why
 * "moved" and "complete" don't appear anywhere here (movement is a
 * location-change row in palletstatus history; an emptied pallet is
 * "empty", not "complete").
 *
 * "missing" is a universal exception reachable from any status of any kind,
 * not listed per-kind below — see Pallet::markMissing()/restoreFromMissing().
 */
class PalletKind
{
    public const RECEIVING = 'R';

    public const WAREHOUSE = 'W';

    public const SHIPPING = 'S';

    public const HOLD = 'H';

    public const QUARANTINE = 'Q';

    public const MISSING = 'missing';

    public const LIFECYCLES = [
        self::RECEIVING => ['received', 'sorting', 'empty'],
        self::WAREHOUSE => ['sealed', 'open', 'empty'],
        self::SHIPPING => ['building', 'ready', 'shipped'],
        self::HOLD => ['filling', 'ready', 'collected'],
        self::QUARANTINE => ['held', 'released', 'condemned'],
    ];

    public const LABELS = [
        self::RECEIVING => 'Receiving',
        self::WAREHOUSE => 'Warehouse',
        self::SHIPPING => 'Shipping',
        self::HOLD => 'Hold',
        self::QUARANTINE => 'Quarantine',
    ];

    public static function initialStatus(string $kind): string
    {
        return self::LIFECYCLES[$kind][0];
    }

    public static function isValidStatus(string $kind, string $status): bool
    {
        return $status === self::MISSING || in_array($status, self::LIFECYCLES[$kind] ?? [], true);
    }

    public const TERMINAL_STATUSES = [
        self::RECEIVING => ['empty'],
        self::WAREHOUSE => ['empty'],
        self::SHIPPING => ['shipped'],
        self::HOLD => ['collected'],
        // Q branches rather than running linearly: fate can resolve either way.
        self::QUARANTINE => ['released', 'condemned'],
    ];

    public static function isTerminal(string $kind, string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES[$kind] ?? [], true);
    }
}
