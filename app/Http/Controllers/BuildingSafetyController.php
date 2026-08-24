<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\BuildingCloseout;
use App\Models\BuildingRollCall;
use App\Models\BuildingRollCallConfirmation;
use App\Models\Person;
use App\Models\VolunteerSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Building-safety design pass (2026-08-23): occupancy count/roster,
 * building closeout, and fire-safety roll call.
 *
 * closeout()/startRollCall()/closeRollCall() are deliberately guest-
 * accessible (no `auth` middleware, see routes/web.php) and PIN-verified
 * internally instead — these are the actions meant to work from a locked
 * kiosk with nobody logged in (once kiosk lock mode is built; until then
 * they're only reachable through the still-auth-gated kiosk page, but
 * building them this way now avoids reworking them later). They never
 * touch login state — unlike PIN *unlock* (UnlockController), which logs
 * someone in, this only resolves "who is performing this one action" via
 * PIN + permission, mirroring UnlockController::attemptPin's checks
 * (PIN match, rate limit, not disabled) without the Auth::login() step.
 */
class BuildingSafetyController extends Controller
{
    /**
     * The kiosk's own "how many people are in the building" button — no
     * names, just a count, so it's safe to expose without login.
     */
    public function kioskOccupancyCount(): JsonResponse
    {
        return response()->json([
            'count' => VolunteerSignIn::occupying()->count(),
            // No names here — safe to expose without login. Lets the
            // kiosk show "Start" vs "Close" for the roll-call action
            // without needing view-building-occupancy itself.
            'active_roll_call_id' => BuildingRollCall::whereNull('closed_at')->value('id'),
        ]);
    }

    /**
     * The full roster with names — for the profile-menu quick-access point
     * and after-the-fact viewing. Reuses "why they were here" fields
     * already on the sign-in record rather than adding new ones.
     */
    public function occupancy(): JsonResponse
    {
        $records = VolunteerSignIn::occupying()
            ->with(['person', 'otherCategory'])
            ->orderBy('signed_in_at')
            ->get()
            ->map(fn (VolunteerSignIn $signIn) => $this->presentOccupant($signIn));

        return response()->json(['records' => $records]);
    }

    /**
     * Same data as occupancy(), but guest-accessible (no login, no PIN) —
     * the kiosk's "Emergency List" button. A firefighter sweeping the
     * building in an actual emergency can't be expected to know anyone's
     * PIN; kioskOccupancyCount() deliberately withholds names for the
     * routine display, but that tradeoff doesn't apply here.
     */
    public function emergencyOccupancyList(): JsonResponse
    {
        // Sorted by last name (falling back to the full display name for an
        // org-only person with no last_name) rather than signed_in_at — a
        // sweep checking names off this list wants it alphabetical, not in
        // arrival order.
        $records = VolunteerSignIn::occupying()
            ->with(['person', 'otherCategory'])
            ->get()
            ->sortBy(fn (VolunteerSignIn $signIn) => strtolower($signIn->person?->last_name ?: $signIn->person?->full_name ?: ''))
            ->values()
            ->map(fn (VolunteerSignIn $signIn) => $this->presentOccupant($signIn));

        return response()->json(['records' => $records]);
    }

    /**
     * Guest-accessible search for the PIN picker — matches everyone who
     * currently holds operate-volunteer-kiosk, not just whoever's logged
     * in, since the whole point is this doesn't depend on a session.
     */
    public function kioskOperatorCandidates(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return response()->json(['records' => []]);
        }

        $people = Person::withPermission('operate-volunteer-kiosk')
            ->where(function ($q) use ($query) {
                $q->where('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%");
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name']);

        return response()->json(['records' => $people]);
    }

    public function closeout(Request $request): JsonResponse
    {
        $actor = $this->resolvePinActor($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $data = $request->validate(['notes' => 'nullable|string']);

        BuildingCloseout::create([
            'closed_at' => now(),
            'closed_by_person_id' => $actor->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['message' => 'Building confirmed empty.']);
    }

    public function startRollCall(Request $request): JsonResponse
    {
        $actor = $this->resolvePinActor($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        if (BuildingRollCall::whereNull('closed_at')->exists()) {
            return response()->json(['message' => 'A roll call is already in progress.'], 422);
        }

        $rollCall = BuildingRollCall::create([
            'started_at' => now(),
            'started_by_person_id' => $actor->id,
        ]);

        // Freeze the roster now — see the create-table migration doc
        // comment on why this isn't computed live for the duration.
        $occupyingIds = VolunteerSignIn::occupying()->pluck('id');
        $rollCall->snapshotSignIns()->attach($occupyingIds);

        return response()->json(['record' => $this->presentRollCall($rollCall)], 201);
    }

    public function closeRollCall(Request $request, BuildingRollCall $buildingRollCall): JsonResponse
    {
        if ($buildingRollCall->closed_at) {
            return response()->json(['message' => 'This roll call is already closed.'], 422);
        }

        $actor = $this->resolvePinActor($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $buildingRollCall->update(['closed_at' => now(), 'closed_by_person_id' => $actor->id]);

        return response()->json(['record' => $this->presentRollCall($buildingRollCall->fresh())]);
    }

    /**
     * The currently active (unclosed) roll call, if any — the working
     * view a phone opens to mark people safe. Normal auth+permission, not
     * PIN-gated: participating isn't a "declare official state" action.
     */
    public function activeRollCall(): JsonResponse
    {
        $rollCall = BuildingRollCall::whereNull('closed_at')->latest('started_at')->first();

        return response()->json(['record' => $rollCall ? $this->presentRollCall($rollCall) : null]);
    }

    public function confirmPerson(Request $request, BuildingRollCall $buildingRollCall, VolunteerSignIn $volunteerSignIn): JsonResponse
    {
        if ($buildingRollCall->closed_at) {
            return response()->json(['message' => 'This roll call is already closed.'], 422);
        }

        BuildingRollCallConfirmation::firstOrCreate(
            ['building_roll_call_id' => $buildingRollCall->id, 'volunteer_sign_in_id' => $volunteerSignIn->id],
            ['confirmed_by_person_id' => $request->user()->id, 'confirmed_at' => now()]
        );

        return response()->json(['record' => $this->presentRollCall($buildingRollCall->fresh())]);
    }

    private function resolvePinActor(Request $request): Person|JsonResponse
    {
        // person_id deliberately not validated with `exists:people,id` —
        // guest-accessible endpoint, same enumeration-avoidance reasoning
        // as UnlockController::attemptPin.
        $data = $request->validate([
            'person_id' => 'required|integer',
            'pin' => 'required|digits:5',
        ]);

        $rateLimitKey = 'kiosk-safety-pin:'.$data['person_id'];
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json(['message' => 'Too many incorrect attempts.'], 429);
        }

        $person = Person::find($data['person_id']);
        if (! $person || ! $person->hasPermission('operate-volunteer-kiosk') || ! $person->verifyPin($data['pin'])) {
            RateLimiter::hit($rateLimitKey, 300);

            return response()->json(['message' => 'Incorrect PIN.'], 401);
        }

        if ($person->isLoginDisabled()) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        RateLimiter::clear($rateLimitKey);

        return $person;
    }

    private function presentOccupant(VolunteerSignIn $signIn): array
    {
        return [
            'id' => $signIn->id,
            'person_id' => $signIn->person_id,
            'name' => $signIn->person?->full_name,
            'signed_in_at' => $signIn->signed_in_at,
            'category' => $signIn->category,
            'why' => $signIn->category === VolunteerSignIn::CATEGORY_OTHER
                ? ($signIn->otherCategory?->name ?? $signIn->other_category_text)
                : 'Volunteer',
            'description_of_work' => $signIn->description_of_work,
        ];
    }

    private function presentRollCall(BuildingRollCall $rollCall): array
    {
        $rollCall->load(['snapshotSignIns.person', 'confirmations']);

        $confirmedIds = $rollCall->confirmations->pluck('volunteer_sign_in_id')->all();
        $snapshot = $rollCall->snapshotSignIns->sortBy(fn ($s) => $s->person?->full_name)->values();

        return [
            'id' => $rollCall->id,
            'started_at' => $rollCall->started_at,
            'closed_at' => $rollCall->closed_at,
            'roster' => $snapshot->map(fn (VolunteerSignIn $signIn) => [
                ...$this->presentOccupant($signIn),
                'confirmed' => in_array($signIn->id, $confirmedIds, true),
            ])->values(),
            'total' => $snapshot->count(),
            'confirmed_count' => count($confirmedIds),
        ];
    }
}
