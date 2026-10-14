<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A paired R Station Raspberry Pi — see DEVICE_CONTRACT.md. The bearer
 * token is only ever stored as a sha256 hash; revocation (revoked_at, or a
 * nulled hash) makes every authenticated call 401, which tells the device
 * to wipe itself and return to setup.
 */
class ManagedDevice extends Model
{
    protected $fillable = [
        'name', 'kiosk_location_id', 'update_channel', 'os_auto_update_enabled', 'kiosk_function',
        'language', 'revoked_at',
    ];

    protected $casts = [
        'os_auto_update_enabled' => 'boolean',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'paired_at' => 'datetime',
        'cpu_temp_c' => 'float',
    ];

    protected $appends = ['online', 'revoked'];

    public function location()
    {
        return $this->belongsTo(KioskLocation::class, 'kiosk_location_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Issues a fresh token, replacing any earlier one; returns the plaintext (shown once, never stored). */
    public function issueToken(): string
    {
        $token = Str::random(60);
        $this->token_hash = self::hashToken($token);

        return $token;
    }

    public function getRevokedAttribute(): bool
    {
        return $this->revoked_at !== null;
    }

    public function getOnlineAttribute(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(config('devices.offline_after_minutes')));
    }
}
