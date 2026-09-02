<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\DeviceTrustGrant;
use App\Models\Person;
use App\Models\PinLoginSetting;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Shared between the normal email+password login (which grants/refreshes
 * trust on a successful login) and the PIN-unlock flow (which reads that
 * trust back). Two independent gates, both required for PIN unlock to
 * work: the device itself must be admin-approved (TrustedDevice::status),
 * and the specific person must hold a live, unexpired grant on it
 * (DeviceTrustGrant) — see the migrations for the full reasoning.
 */
class PinLoginService
{
    private const COOKIE_NAME = 'pin_device_token';

    private const COOKIE_YEARS = 5;

    public function settings(): PinLoginSetting
    {
        return PinLoginSetting::current();
    }

    /**
     * Get-or-create the TrustedDevice for this browser, identified by an
     * opaque cookie token — never anything that fingerprints the actual
     * hardware. A brand-new device starts 'pending' and stays that way
     * until an admin approves it; nothing about visiting this page grants
     * any access on its own.
     */
    public function resolveDevice(Request $request): TrustedDevice
    {
        $token = $request->cookie(self::COOKIE_NAME);

        if ($token) {
            $device = TrustedDevice::where('device_token', $token)->first();
            if ($device) {
                $device->update(['last_seen_at' => now()]);

                return $device;
            }
        }

        $token = Str::random(64);
        Cookie::queue(Cookie::make(self::COOKIE_NAME, $token, 60 * 24 * 365 * self::COOKIE_YEARS, null, null, null, true));

        return TrustedDevice::create([
            'device_token' => $token,
            'status' => 'pending',
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'requested_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Called on every successful real email+password login. No-ops
     * entirely when the feature is off or the device isn't approved —
     * trust is never silently granted on an unapproved device just
     * because someone happened to log in on it.
     *
     * Takes a plain person id, not a Person/User model instance — Person
     * and User are two separate classes over the same `people` table (see
     * CLAUDE.md), and the caller here (AuthenticatedSessionController)
     * only ever has a User in hand; the id is all this actually needs.
     */
    public function grantTrust(TrustedDevice $device, int $personId): void
    {
        $settings = $this->settings();
        if (! $settings->enabled || ! $device->isApproved()) {
            return;
        }

        $grantedAt = now();
        DeviceTrustGrant::updateOrCreate(
            ['trusted_device_id' => $device->id, 'person_id' => $personId],
            ['granted_at' => $grantedAt, 'expires_at' => $settings->computeExpiry($grantedAt)]
        );
    }

    /**
     * Whether "Switch User" should be offered on the current request's
     * device — feature on, and this device already approved for PIN
     * unlock. Deliberately read-only (unlike resolveDevice()): this runs
     * on every authenticated page load via HandleInertiaRequests, so it
     * must never create a TrustedDevice row or queue a cookie as a side
     * effect of just rendering a page. No device cookie yet simply means
     * no switch-user shortcut yet, same as a first-ever visit.
     */
    public function switchUserAvailable(Request $request): bool
    {
        if (! $this->settings()->enabled) {
            return false;
        }

        $token = $request->cookie(self::COOKIE_NAME);
        if (! $token) {
            return false;
        }

        return (bool) TrustedDevice::where('device_token', $token)->first()?->isApproved();
    }

    /**
     * Read-only device lookup for the kiosk's guest-access guard — unlike
     * resolveDevice(), never creates a TrustedDevice or queues a cookie.
     * No cookie yet simply means this device has never been approved for
     * anything, so it can't be in kiosk mode either.
     */
    public function deviceFromCookie(Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE_NAME);
        if (! $token) {
            return null;
        }

        return TrustedDevice::where('device_token', $token)->first();
    }

    /**
     * Puts THIS device into kiosk mode — from this point, the kiosk page/
     * endpoints are reachable on it with no login at all (see
     * KioskModeController and the guest-access middleware). Requires PIN
     * unlock to be enabled and this specific device already admin-approved
     * — same two-gate reasoning as PIN unlock itself, see the class doc
     * comment. Caller is responsible for logging the enabling person out
     * immediately after — this method only flips the flag.
     */
    public function enableKioskMode(TrustedDevice $device, int $personId): bool
    {
        if (! $this->settings()->enabled || ! $device->isApproved()) {
            return false;
        }

        $device->update([
            'kiosk_mode' => true,
            'kiosk_mode_enabled_at' => now(),
            'kiosk_mode_enabled_by_person_id' => $personId,
        ]);

        return true;
    }

    /**
     * Called after any successful real login (password or PIN) — a kiosk
     * device stops being one the instant someone's actually signed in on
     * it, so "getting back to the real app" is just logging in, nothing
     * separate to remember or undo. Returns whether the device actually
     * was in kiosk mode, so callers can route someone returning from a
     * locked kiosk back to the kiosk page (with its "Confirm Building
     * Empty" action available) instead of straight to the dashboard.
     */
    public function clearKioskMode(TrustedDevice $device): bool
    {
        if ($device->kiosk_mode) {
            $device->update(['kiosk_mode' => false]);

            return true;
        }

        return false;
    }

    public function activeGrant(TrustedDevice $device, int $personId): ?DeviceTrustGrant
    {
        return DeviceTrustGrant::active()
            ->where('trusted_device_id', $device->id)
            ->where('person_id', $personId)
            ->first();
    }

    /**
     * People to show as tappable tiles on the unlock screen — everyone
     * with a currently-active grant on this device who has actually set a
     * PIN. A grant is created on every login regardless of PIN status (see
     * grantTrust()), so without this filter someone who's never set a PIN
     * would still get a tile that can only ever fail to unlock.
     */
    public function peopleTrustedOnDevice(TrustedDevice $device): Collection
    {
        return DeviceTrustGrant::active()
            ->where('trusted_device_id', $device->id)
            ->with('person')
            ->get()
            ->pluck('person')
            ->filter(fn (?Person $person) => $person?->hasPin());
    }
}
