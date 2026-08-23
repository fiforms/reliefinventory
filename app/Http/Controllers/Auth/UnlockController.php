<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Person;
use App\Models\User;
use App\Services\PinLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared-terminal PIN unlock — a faster re-auth for someone who's already
 * done a real email+password login on this specific, admin-approved
 * device, not a weaker substitute for one anywhere else. Two independent
 * gates checked on every request here (never just one): the device must
 * be approved (TrustedDevice::status), and the specific person must hold
 * a live, unexpired grant on it (DeviceTrustGrant) — see PinLoginService.
 */
class UnlockController extends Controller
{
    private const BADGE_VERIFICATION_TTL_SECONDS = 60;

    public function show(Request $request, PinLoginService $pinLogin): Response|RedirectResponse
    {
        $settings = $pinLogin->settings();

        if (! $settings->enabled) {
            return redirect()->route('login', ['email' => 1]);
        }

        $device = $pinLogin->resolveDevice($request);
        $people = $device->isApproved() ? $pinLogin->peopleTrustedOnDevice($device) : collect();

        return Inertia::render('Auth/Unlock', [
            'deviceApproved' => $device->isApproved(),
            'people' => $people->map(fn (Person $p) => ['id' => $p->id, 'full_name' => $p->full_name])->values(),
            'requireBadgeAndPin' => $settings->require_badge_and_pin,
        ]);
    }

    /**
     * Resolve a scanned/typed badge code to a person, and mark them
     * badge-verified for a short window — the PIN submission that follows
     * checks this marker when require_badge_and_pin is on. A badge alone
     * never logs anyone in; it only ever identifies who's about to try a
     * PIN, same as tapping a name tile does when that setting is off.
     */
    public function scanBadge(Request $request, PinLoginService $pinLogin)
    {
        $data = $request->validate(['badge_code' => 'required|string|max:255']);

        $settings = $pinLogin->settings();
        $device = $pinLogin->resolveDevice($request);
        if (! $settings->enabled || ! $device->isApproved()) {
            return response()->json(['message' => 'PIN unlock is not available on this device.'], 403);
        }

        $person = Person::where('badge_code', $data['badge_code'])->first();
        if (! $person) {
            return response()->json(['message' => 'Badge not recognized.'], 404);
        }

        if (! $pinLogin->activeGrant($device, $person->id)) {
            return response()->json([
                'message' => 'This badge isn\'t set up for quick login on this device yet — log in with email first.',
            ], 401);
        }

        $request->session()->put('pin_badge_verified_person_id', $person->id);
        $request->session()->put('pin_badge_verified_at', now()->toIso8601String());

        return response()->json(['person' => ['id' => $person->id, 'full_name' => $person->full_name]]);
    }

    public function attemptPin(Request $request, PinLoginService $pinLogin)
    {
        // person_id is deliberately NOT validated with `exists:people,id` here —
        // this is a guest-accessible endpoint, and an `exists` failure would let
        // an unauthenticated caller enumerate valid person IDs before the
        // device-approval check below even runs. An invalid ID instead just
        // fails the activeGrant() check further down with the same generic
        // "session expired" message as any other invalid grant.
        $data = $request->validate([
            'person_id' => 'required|integer',
            'pin' => 'required|digits:5',
        ]);

        $settings = $pinLogin->settings();
        $device = $pinLogin->resolveDevice($request);
        if (! $settings->enabled || ! $device->isApproved()) {
            return response()->json(['message' => 'PIN unlock is not available on this device.'], 403);
        }

        if (! $pinLogin->activeGrant($device, $data['person_id'])) {
            return response()->json(['message' => 'Your quick-login session has expired. Please log in with email.'], 401);
        }

        $rateLimitKey = 'pin-unlock:'.$device->device_token.':'.$data['person_id'];
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'message' => 'Too many incorrect attempts. Please log in with email.',
            ], 429);
        }

        if ($settings->require_badge_and_pin && ! $this->badgeRecentlyVerified($request, $data['person_id'])) {
            return response()->json(['message' => 'Please scan your badge first.'], 422);
        }

        $person = Person::findOrFail($data['person_id']);

        if (! $person->verifyPin($data['pin'])) {
            RateLimiter::hit($rateLimitKey, 300);

            return response()->json(['message' => 'Incorrect PIN.'], 401);
        }

        if ($person->isLoginDisabled()) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        RateLimiter::clear($rateLimitKey);
        $request->session()->forget(['pin_badge_verified_person_id', 'pin_badge_verified_at']);

        Auth::login(User::findOrFail($person->id));
        $request->session()->regenerate();

        LoginHistory::record($person->id, 'pin', $request->ip(), $request->userAgent());

        // A successful PIN unlock re-proves presence on the device, so it's
        // worth refreshing the grant's expiry too (session_duration mode
        // especially — otherwise a person who's been actively working
        // could still get bumped mid-shift at the original login's expiry).
        $pinLogin->grantTrust($device, $person->id);
        $pinLogin->clearKioskMode($device);

        return response()->json(['redirect' => route('dashboard')]);
    }

    private function badgeRecentlyVerified(Request $request, int $personId): bool
    {
        if ($request->session()->get('pin_badge_verified_person_id') !== $personId) {
            return false;
        }

        $verifiedAt = $request->session()->get('pin_badge_verified_at');

        return $verifiedAt && now()->diffInSeconds($verifiedAt) < self::BADGE_VERIFICATION_TTL_SECONDS;
    }
}
