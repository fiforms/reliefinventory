<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton (always id=1) — see the 2026_08_16 migration for why this
 * shape rather than a generic key-value settings table: a handful of
 * well-known, validated fields is simpler to reason about than a generic
 * store for exactly one feature's config.
 */
class PinLoginSetting extends Model
{
    protected $table = 'pin_login_settings';

    protected $fillable = [
        'enabled',
        'trust_mode',
        'trust_time_of_day',
        'trust_session_minutes',
        'require_badge_and_pin',
        'badge_login_enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'require_badge_and_pin' => 'boolean',
        'badge_login_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /**
     * When a device_trust_grants row granted "now" should expire, per the
     * current trust_mode. Null means "never" (indefinite mode).
     */
    public function computeExpiry(Carbon $grantedAt): ?Carbon
    {
        return match ($this->trust_mode) {
            'indefinite' => null,
            'session_duration' => (clone $grantedAt)->addMinutes($this->trust_session_minutes ?? 480),
            'time_of_day' => $this->nextOccurrenceOf($grantedAt, $this->trust_time_of_day),
        };
    }

    /**
     * The next time trust_time_of_day occurs at/after $from — today if that
     * clock time hasn't passed yet, otherwise tomorrow. A daily reset, not
     * a rolling window: everyone granted trust today loses it at the same
     * clock time regardless of when during the day they logged in.
     */
    private function nextOccurrenceOf(Carbon $from, ?string $timeOfDay): ?Carbon
    {
        if (! $timeOfDay) {
            return null;
        }

        $candidate = (clone $from)->setTimeFromTimeString($timeOfDay);

        return $candidate->lessThanOrEqualTo($from) ? $candidate->addDay() : $candidate;
    }
}
