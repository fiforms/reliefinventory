<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\User;
use App\Models\VolunteerSignIn;
use App\Notifications\KioskCheckInAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * The facility sign-in kiosk's JSON API. See VolunteerSignIn and the
 * create-table migrations for the field/status design.
 */
class VolunteerSignInController extends Controller
{
    private const WITH = ['currentSignIn', 'lastSignIn', 'forgottenSignIn'];

    /**
     * The kiosk's default tile grid: active volunteers, alphabetical, each
     * carrying whatever open/pending_confirmation sign-in they currently
     * have (so a tile can render as "signed in since ..." rather than a
     * plain name) plus their most recent closed sign-in (a suggestion for
     * pre-filling agency/work description on the confirm screen — not a
     * stored fact, since agency can change visit to visit).
     */
    public function roster()
    {
        $people = Person::where('is_volunteer', true)
            ->where('volunteer_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->with(self::WITH)
            ->get();

        return response()->json(['records' => $people]);
    }

    /**
     * The scroll-vs-search fallback: matches across every person (not just
     * the active-gated roster above), so a deactivated regular or a
     * first-time walk-in who already has a Person record can still be
     * found and signed in.
     */
    public function search(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return response()->json(['records' => []]);
        }

        // Prefix match on name (not substring) — kiosk operators type from
        // the start of a first or last name, and substring matching was
        // pulling in unrelated people whose name merely contained the
        // typed letters partway through (e.g. "mar" matching "Walmart").
        // Organization matches the start of *any word* in it rather than a
        // pure prefix, since those are often searched by an internal word
        // (e.g. "Cross" for "American Red Cross").
        $people = Person::where(function ($q) use ($query) {
            $q->where('first_name', 'like', "{$query}%")
                ->orWhere('last_name', 'like', "{$query}%")
                ->orWhere('organization', 'like', "{$query}%")
                ->orWhere('organization', 'like', "% {$query}%");
        })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->with(self::WITH)
            ->limit(25)
            ->get();

        return response()->json(['records' => $people]);
    }

    /**
     * Quick-add for a first-time walk-in the kiosk search can't find — same
     * "add new" idea as SearchSelect's allowcreate, but a dedicated
     * endpoint (rather than routing through PeopleController) since kiosk
     * operators only hold operate-kiosk, not manage-people.
     * Deliberately minimal: name only, defaults to an active volunteer.
     */
    public function quickCreatePerson(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required_without:last_name|nullable|string|max:255',
            'last_name' => 'required_without:first_name|nullable|string|max:255',
        ]);

        $person = Person::create([
            ...$data,
            'is_volunteer' => true,
            'volunteer_active' => true,
        ]);

        return response()->json(['record' => $person->fresh(self::WITH)], 201);
    }

    /**
     * Quick-add for a one-off guest (visitor, inspector, state/other
     * representative — anyone who isn't going to become a searchable
     * regular). Deliberately NOT flagged is_volunteer/volunteer_active,
     * unlike quickCreatePerson — a guest never joins the active roster or
     * shows up in the type-ahead search.
     */
    public function quickCreateGuest(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required_without:last_name|nullable|string|max:255',
            'last_name' => 'required_without:first_name|nullable|string|max:255',
        ]);

        $person = Person::create([
            ...$data,
            'is_volunteer' => false,
            'volunteer_active' => false,
        ]);

        return response()->json(['record' => $person->fresh(self::WITH)], 201);
    }

    /**
     * Sign a person in. Rejects if they already have an open or
     * pending_confirmation sign-in — that's a sign-out (or a
     * confirm-and-correct), not a second sign-in.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'person_id' => 'required|exists:people,id',
            'category' => 'required|in:'.VolunteerSignIn::CATEGORY_VOLUNTEER.','.VolunteerSignIn::CATEGORY_OTHER,
            'other_category_id' => 'nullable|exists:sign_in_categories,id',
            'other_category_text' => 'nullable|string|max:255',
            'agency' => 'nullable|string|max:255',
            'title_function' => 'nullable|string|max:255',
            'work_site' => 'nullable|string|max:255',
            'description_of_work' => 'nullable|string',
            'expected_departure_at' => 'nullable|date',
        ]);

        $alreadyIn = VolunteerSignIn::where('person_id', $data['person_id'])
            ->whereIn('status', [VolunteerSignIn::STATUS_OPEN, VolunteerSignIn::STATUS_PENDING_CONFIRMATION])
            ->exists();
        if ($alreadyIn) {
            return response()->json(['message' => 'This person already has an open sign-in — sign them out instead.'], 422);
        }

        $signIn = VolunteerSignIn::create([
            ...$data,
            'signed_in_at' => now(),
            'status' => VolunteerSignIn::STATUS_OPEN,
        ]);

        $this->maybeNotifyCheckIn($signIn);

        return response()->json(['record' => $signIn->fresh(['person', 'otherCategory'])], 201);
    }

    /**
     * Alert Administrator/Office/Team Leader of a check-in staff should
     * know about right away: a guest (any 'other'-category sign-in) or a
     * volunteer's first-ever sign-in. Deliberately skipped for a routine
     * returning volunteer — an alert on every ordinary sign-in would
     * quickly become noise nobody reads. "First-ever" is derived from the
     * data (this person's only VolunteerSignIn row) rather than a
     * client-supplied flag, since the New Volunteer kiosk flow is the only
     * path that can produce that state.
     */
    private function maybeNotifyCheckIn(VolunteerSignIn $signIn): void
    {
        $isGuest = $signIn->category === VolunteerSignIn::CATEGORY_OTHER;
        $isFirstEverVolunteerSignIn = $signIn->category === VolunteerSignIn::CATEGORY_VOLUNTEER
            && VolunteerSignIn::where('person_id', $signIn->person_id)->count() === 1;

        if (! $isGuest && ! $isFirstEverVolunteerSignIn) {
            return;
        }

        $recipients = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Administrator', 'Office', 'Team Leader']))->get();

        Notification::send($recipients, new KioskCheckInAlert($signIn));
    }

    /**
     * Sign out an open (or pending_confirmation — the same tap resolves
     * the forgotten-sign-out case by confirming the correct end time)
     * sign-in.
     */
    public function signOut(Request $request, VolunteerSignIn $volunteerSignIn)
    {
        if ($volunteerSignIn->status === VolunteerSignIn::STATUS_CLOSED) {
            return response()->json(['message' => 'This sign-in is already closed.'], 422);
        }

        $data = $request->validate([
            'signed_out_at' => 'nullable|date',
        ]);

        $volunteerSignIn->applyChanges([
            'signed_out_at' => $data['signed_out_at'] ?? now(),
            'status' => VolunteerSignIn::STATUS_CLOSED,
        ], Auth::id());

        return response()->json(['record' => $volunteerSignIn->fresh(['person', 'otherCategory'])]);
    }

    /**
     * Edit an existing sign-in — corrections, and resolving a
     * pending_confirmation row (the volunteer confirming/correcting at
     * their next sign-in, or a manager override). Every changed field
     * writes an audit-log row via applyChanges().
     */
    public function update(Request $request, VolunteerSignIn $volunteerSignIn)
    {
        $data = $request->validate([
            'category' => 'sometimes|in:'.VolunteerSignIn::CATEGORY_VOLUNTEER.','.VolunteerSignIn::CATEGORY_OTHER,
            'other_category_id' => 'nullable|exists:sign_in_categories,id',
            'other_category_text' => 'nullable|string|max:255',
            'agency' => 'nullable|string|max:255',
            'title_function' => 'nullable|string|max:255',
            'work_site' => 'nullable|string|max:255',
            'description_of_work' => 'nullable|string',
            'expected_departure_at' => 'nullable|date',
            'signed_in_at' => 'sometimes|date',
            'signed_out_at' => 'nullable|date',
            'status' => 'sometimes|in:'.implode(',', [
                VolunteerSignIn::STATUS_OPEN,
                VolunteerSignIn::STATUS_PENDING_CONFIRMATION,
                VolunteerSignIn::STATUS_CLOSED,
            ]),
        ]);

        $volunteerSignIn->applyChanges($data, Auth::id());

        return response()->json(['record' => $volunteerSignIn->fresh(['person', 'otherCategory', 'auditLog.changedBy'])]);
    }

    /**
     * Bulk-certify a batch of sign-ins — the FEMA-compliance-critical step
     * (see PROJECT_ANALYSIS.md ~line 258), gated separately on
     * certify-volunteer-hours rather than the broad kiosk-operator key.
     */
    public function certify(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:volunteer_sign_ins,id',
        ]);

        VolunteerSignIn::whereIn('id', $data['ids'])
            ->whereNull('certified_at')
            ->update([
                'certified_at' => now(),
                'certified_by_person_id' => Auth::id(),
            ]);

        return response()->json(['message' => 'Certified.']);
    }
}
