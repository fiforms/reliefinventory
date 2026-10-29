<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Single-use, short-lived handoff from a paired device's backend to its browser. See ManagedDevice::issueSessionToken(). */
class DeviceSessionTicket extends Model
{
    public const TTL_SECONDS = 60;

    protected $fillable = ['managed_device_id', 'ticket_hash', 'expires_at', 'used_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    /** Creates a ticket for the device; returns the plaintext (never stored). */
    public static function issueFor(ManagedDevice $device): string
    {
        // Housekeeping: old tickets are worthless.
        static::where('expires_at', '<', now()->subHour())->delete();

        $ticket = Str::random(48);
        static::create([
            'managed_device_id' => $device->id,
            'ticket_hash' => ManagedDevice::hashToken($ticket),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $ticket;
    }

    /**
     * Spends a ticket atomically; the device it belonged to, or null if it is unknown, expired or
     * already used. The conditional update is what makes "single use" hold under concurrent requests.
     */
    public static function redeem(string $ticket): ?ManagedDevice
    {
        $row = static::where('ticket_hash', ManagedDevice::hashToken($ticket))->first();
        if (! $row || $row->expires_at->isPast()) {
            return null;
        }
        $claimed = static::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed ? ManagedDevice::find($row->managed_device_id) : null;
    }
}
