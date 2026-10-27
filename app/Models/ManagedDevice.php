<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Services\Warehouses\WarehouseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A paired R Station Raspberry Pi or desktop print server — see DEVICE_CONTRACT.md. It authenticates as a client-kind
 * Trust (`trust_id`; App\Services\Federation), not with a token of its own; revocation (revoked_at, which revoking the
 * trust sets) makes every authenticated call 401, which tells the device to wipe its pairing and return to setup.
 */
class ManagedDevice extends Model
{
    public const SESSION_COOKIE = 'device_session';

    /** The device-session cookie's name in the current warehouse (one per warehouse, MULTI_WAREHOUSE.md §3). */
    public static function sessionCookie(): string
    {
        return app(WarehouseContext::class)->cookieName(self::SESSION_COOKIE);
    }

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
        'reported_printers' => 'array',
        'printers_reported_at' => 'datetime',
    ];

    protected $appends = ['online', 'revoked', 'device_type'];

    // The wake-up channel name is a capability: never serialize it to the admin UI.
    protected $hidden = ['wake_channel', 'session_token_hash'];

    public function trust()
    {
        return $this->belongsTo(Trust::class);
    }

    public function location()
    {
        return $this->belongsTo(KioskLocation::class, 'kiosk_location_id');
    }

    /** The print queues this device (as a print server) owns — see Printer. */
    public function printers()
    {
        return $this->hasMany(Printer::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Name of this device's private wake-up channel (App\Events\PrintJobAvailable).
     * Unguessable on purpose — knowing it is the only "auth" the public channel
     * has — and generated on first use. Handed to the device inside its
     * authenticated heartbeat response.
     */
    public function wakeChannel(): string
    {
        if (! $this->wake_channel) {
            $this->wake_channel = 'wake.'.Str::random(40);
            $this->save();
        }

        return $this->wake_channel;
    }

    /**
     * Starts the device's browser session (the cookie that opens its allow-listed pages with nobody
     * logged in); returns the plaintext cookie value. One live session per device: issuing a new one
     * (every launcher run does) replaces the old.
     */
    public function issueSessionToken(): string
    {
        $token = Str::random(60);
        $this->session_token_hash = self::hashToken($token);
        $this->save();

        return $token;
    }

    /** The device behind a browser's session cookie, or null (no cookie, unknown, or revoked). */
    public static function fromSessionToken(?string $token): ?self
    {
        if (! $token) {
            return null;
        }
        $device = static::where('session_token_hash', self::hashToken($token))->first();

        return $device && ! $device->revoked_at ? $device : null;
    }

    /** The paired device a request's browser is, if any — see EnsureDeviceAccess. */
    public static function fromRequest(Request $request): ?self
    {
        return static::fromSessionToken($request->cookie(self::sessionCookie()));
    }

    /**
     * 'print_server' for the desktop software print client (reports windows/macos/linux), else
     * 'rstation' — the Raspberry Pi platform, including one running as a print station, which
     * reports platform 'rstation' or nothing at all. Print servers are not managed firmware
     * devices: they get no update offers and no channel/OS-update settings.
     */
    public function getDeviceTypeAttribute(): string
    {
        return in_array($this->platform, ['windows', 'macos', 'linux'], true) ? 'print_server' : 'rstation';
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
