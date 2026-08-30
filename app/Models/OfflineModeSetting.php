<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1) — one instance-wide switch for a warehouse with
 * no reliable internet, rather than a checklist of individual internet-
 * dependent features an administrator has to remember to turn off
 * separately. See the 2026_08_30 migration for the full rationale.
 */
class OfflineModeSetting extends Model
{
    protected $table = 'offline_mode_settings';

    protected $fillable = ['enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /**
     * Checked wherever a feature depends on reaching the outside internet
     * (Cloudflare Turnstile, geocod.io) — the single gate both defer to,
     * on top of (never instead of) their own individual enabled checks
     * (a missing API key or CLOUDFLARE_TURNSTILE_ENABLED=false already
     * disables a feature regardless of this setting).
     */
    public static function isOffline(): bool
    {
        return self::current()->enabled;
    }
}
