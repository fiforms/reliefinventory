<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

/**
 * Turns a donor's sorted-goods history into a *suggested* standing. Only a suggestion: a person with
 * manage-donor-standing decides, and nothing downstream is gated on the result. Thresholds live in
 * config('inventory.donor_standing') so they can be tested and refined without a deploy of new code.
 */
class DonorStanding
{
    public const PREFERRED = 'preferred';

    public const NORMAL = 'normal';

    public const WATCH = 'watch';

    public const DECLINE = 'decline';

    public const ALL = [self::PREFERRED, self::NORMAL, self::WATCH, self::DECLINE];

    /** Loss % = (outdated + trashed) / everything sorted; diverted goods are usable elsewhere, so they don't count against a donor. */
    public static function lossPercent(int $total, int $outdated, int $trashed): ?float
    {
        return $total > 0 ? round(($outdated + $trashed) / $total * 100, 1) : null;
    }

    /**
     * @return array{standing: ?string, reason: string} standing is null when there isn't enough history to say
     */
    public static function suggest(int $donations, int $units, ?float $lossPercent): array
    {
        $c = config('inventory.donor_standing');

        if ($lossPercent === null || $donations < $c['min_donations'] || $units < $c['min_units']) {
            return ['standing' => null, 'reason' => "Not enough history yet (needs {$c['min_donations']}+ sorted donations and {$c['min_units']}+ units)."];
        }

        if ($lossPercent >= $c['decline_loss_percent'] && $donations >= $c['decline_min_donations']) {
            return ['standing' => self::DECLINE, 'reason' => "{$lossPercent}% lost across {$donations} donations (decline at {$c['decline_loss_percent']}%+ over {$c['decline_min_donations']}+)."];
        }

        if ($lossPercent >= $c['watch_loss_percent']) {
            return ['standing' => self::WATCH, 'reason' => "{$lossPercent}% lost across {$donations} donations (watch at {$c['watch_loss_percent']}%+)."];
        }

        if ($lossPercent <= $c['preferred_loss_percent']
            && $donations >= $c['preferred_min_donations'] && $units >= $c['preferred_min_units']) {
            return ['standing' => self::PREFERRED, 'reason' => "Only {$lossPercent}% lost across {$donations} donations and ".number_format($units).' units.'];
        }

        return ['standing' => self::NORMAL, 'reason' => "{$lossPercent}% lost across {$donations} donations."];
    }
}
