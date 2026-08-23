<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DonationOffer;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Pre-arrival donation offers: log a call, decide (approve/refuse/divert),
 * track accepted offers awaiting arrival, and match them to the real
 * Receiving intake once goods show up. Recording an offer is gated the
 * same as Receiving itself (manage-receiving — anyone answering the phone
 * can log a call); the decision actions require manage-donation-offers,
 * a narrower key given to whichever staff actually field these calls.
 */
class DonationOfferController extends Controller
{
    // person.orderDonations/donationOffers give the decision screen the
    // donor's past history without a separate request — this donor
    // shouldn't be evaluated blind. Used everywhere a record is returned so
    // the frontend never has to merge a partially-loaded response over one
    // that already had history.
    private const WITH = [
        'person.orderDonations.status',
        'person.donationOffers.statusLogs',
        'contactPerson',
        'enteredBy',
        'statusLogs.changedBy',
        'donation',
    ];

    /**
     * All offers, RIForm {records, templates} shape. The ETA-sorted
     * "pending" worklist and the full history view (DonationOffers.vue) are
     * both client-side filter/sort slices of this one list (same idiom as
     * Receiving's "flagged for donor ID only" toggle). The optional
     * ?status= filter is for a different caller: Receiving.vue's "Match to
     * a phoned-in offer?" picker, a plain SearchSelect (not RIForm) that
     * needs the narrowing done server-side.
     */
    public function index(Request $request)
    {
        $query = DonationOffer::with(self::WITH)->orderByDesc('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $records = $query->get();

        $records->each(function (DonationOffer $offer) {
            $offer->is_overdue = $offer->status === DonationOffer::STATUS_PENDING
                && $offer->eta_end !== null
                && Carbon::parse($offer->eta_end)->isPast();
            // Flat display field for SearchSelect (Receiving.vue's picker),
            // which can't traverse into nested person.full_name.
            $offer->label = ($offer->person->full_name ?? 'Unknown donor')
                .($offer->eta_start ? ' — ETA '.$offer->etaRangeLabel() : '');
        });

        return response()->json([
            'records' => $records,
            'templates' => [
                '_default' => [
                    'person_id' => null,
                    'contact_person_id' => null,
                    'description' => null,
                    'eta_start' => null,
                    'eta_end' => null,
                    // isEditableStatus() in DonationOffers.vue keys off this
                    // to enable the donor/contact pickers — without it a new
                    // record's status is undefined and every field renders
                    // as a disabled, unclickable span.
                    'status' => DonationOffer::STATUS_OFFERED,
                ],
            ],
        ]);
    }

    /**
     * Donation intakes not yet linked to any offer — feeds the
     * after-the-fact match picker on the pending-match worklist.
     */
    public function unmatchedDonations()
    {
        $donations = Transaction::where('type', 'donation')
            ->whereDoesntHave('donationOffer')
            ->with(['person', 'contactPerson'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Flat display field for SearchSelect, same reason as index()'s label.
        $donations->each(function (Transaction $donation) {
            $donation->label = ($donation->person->full_name ?? 'Unknown donor')
                .' — '.($donation->order_date ?? $donation->created_at->format('Y-m-d'))
                .' (#'.$donation->id.')';
        });

        return response()->json(['records' => $donations]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'person_id' => 'required|exists:people,id',
            'contact_person_id' => 'nullable|exists:people,id',
            'description' => 'nullable|string',
            'eta_start' => 'nullable|date',
            'eta_end' => 'nullable|date|after_or_equal:eta_start',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'nullable|string',
        ]);

        $offer = DonationOffer::create([
            'person_id' => $data['person_id'],
            'contact_person_id' => $data['contact_person_id'] ?? null,
            'description' => $data['description'] ?? null,
            'eta_start' => $data['eta_start'] ?? null,
            'eta_end' => $data['eta_end'] ?? null,
            'status' => DonationOffer::STATUS_OFFERED,
            'entered_by_person_id' => Auth::id(),
        ]);

        $offer->statusLogs()->create([
            'from_status' => null,
            'to_status' => DonationOffer::STATUS_OFFERED,
            'changed_by_person_id' => Auth::id(),
            'contact_method' => $data['contact_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['record' => $offer->fresh(self::WITH)], 201);
    }

    /**
     * Edit the call details while nothing's been decided yet (or while
     * still awaiting arrival). Locked once a decision has been made and
     * logged — a refused/diverted/cancelled/received offer is history, not
     * an editable record.
     */
    public function update(Request $request, DonationOffer $donationOffer)
    {
        if (! in_array($donationOffer->status, [DonationOffer::STATUS_OFFERED, DonationOffer::STATUS_PENDING], true)) {
            return response()->json(['message' => 'This offer has already been decided and can\'t be edited.'], 422);
        }

        $data = $request->validate([
            'person_id' => 'required|exists:people,id',
            'contact_person_id' => 'nullable|exists:people,id',
            'description' => 'nullable|string',
            'eta_start' => 'nullable|date',
            'eta_end' => 'nullable|date|after_or_equal:eta_start',
        ]);

        $donationOffer->update($data);

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }

    /**
     * A follow-up call that doesn't decide anything — the donor calls back
     * to push the ETA or add detail before anyone's approved/refused it
     * yet. Same "offered or pending only" lock as update(), but always
     * appends a status_log row (via logNote()) so the conversation history
     * stays complete, same idea as FeedbackReportController's
     * same-status-note update.
     */
    public function addNote(Request $request, DonationOffer $donationOffer)
    {
        if (! in_array($donationOffer->status, [DonationOffer::STATUS_OFFERED, DonationOffer::STATUS_PENDING], true)) {
            return response()->json(['message' => 'This offer has already been decided and can\'t be updated.'], 422);
        }

        $data = $request->validate([
            'eta_start' => 'nullable|date',
            'eta_end' => 'nullable|date|after_or_equal:eta_start',
            'description' => 'nullable|string',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'required|string',
        ]);

        $donationOffer->logNote(
            Auth::id(),
            [
                'eta_start' => $data['eta_start'] ?? $donationOffer->eta_start,
                'eta_end' => $data['eta_end'] ?? $donationOffer->eta_end,
                'description' => $data['description'] ?? $donationOffer->description,
            ],
            $data['contact_method'] ?? null,
            $data['notes'],
        );

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }

    public function approve(Request $request, DonationOffer $donationOffer)
    {
        if ($donationOffer->status !== DonationOffer::STATUS_OFFERED) {
            return response()->json(['message' => 'Only an offered donation can be approved.'], 422);
        }

        $data = $request->validate([
            'eta_start' => 'required|date',
            'eta_end' => 'nullable|date|after_or_equal:eta_start',
            'transit_notes' => 'nullable|string',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'nullable|string',
        ]);

        $donationOffer->transitionTo(
            DonationOffer::STATUS_PENDING,
            Auth::id(),
            [
                'eta_start' => $data['eta_start'],
                'eta_end' => $data['eta_end'] ?? null,
                'transit_notes' => $data['transit_notes'] ?? null,
            ],
            $data['contact_method'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }

    public function refuse(Request $request, DonationOffer $donationOffer)
    {
        if ($donationOffer->status !== DonationOffer::STATUS_OFFERED) {
            return response()->json(['message' => 'Only an offered donation can be refused.'], 422);
        }

        $data = $request->validate([
            'refused_reason' => 'required|string',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'nullable|string',
        ]);

        $donationOffer->transitionTo(
            DonationOffer::STATUS_REFUSED,
            Auth::id(),
            ['refused_reason' => $data['refused_reason']],
            $data['contact_method'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }

    public function divert(Request $request, DonationOffer $donationOffer)
    {
        if ($donationOffer->status !== DonationOffer::STATUS_OFFERED) {
            return response()->json(['message' => 'Only an offered donation can be diverted.'], 422);
        }

        $data = $request->validate([
            'diverted_to' => 'required|string',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'nullable|string',
        ]);

        $donationOffer->transitionTo(
            DonationOffer::STATUS_DIVERTED,
            Auth::id(),
            ['diverted_to' => $data['diverted_to']],
            $data['contact_method'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }

    public function cancel(Request $request, DonationOffer $donationOffer)
    {
        if ($donationOffer->status !== DonationOffer::STATUS_PENDING) {
            return response()->json(['message' => 'Only a pending donation offer can be cancelled.'], 422);
        }

        $data = $request->validate([
            'cancelled_reason' => 'required|string',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'nullable|string',
        ]);

        $donationOffer->transitionTo(
            DonationOffer::STATUS_CANCELLED,
            Auth::id(),
            ['cancelled_reason' => $data['cancelled_reason']],
            $data['contact_method'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }

    /**
     * After-the-fact match: link a pending offer to a donation that already
     * arrived and was entered at Receiving without being matched at intake
     * time. Matching at intake time itself goes through
     * ReceivingController::store() instead, which calls transitionTo() the
     * same way.
     */
    public function match(Request $request, DonationOffer $donationOffer)
    {
        if ($donationOffer->status !== DonationOffer::STATUS_PENDING) {
            return response()->json(['message' => 'Only a pending donation offer can be matched.'], 422);
        }

        $data = $request->validate([
            'donation_id' => 'required|exists:orderdonations,id',
            'contact_method' => 'nullable|in:phone,email,in_person,other',
            'notes' => 'nullable|string',
        ]);

        if (DonationOffer::where('donation_id', $data['donation_id'])->exists()) {
            return response()->json(['message' => 'That donation is already matched to another offer.'], 422);
        }

        $donationOffer->transitionTo(
            DonationOffer::STATUS_RECEIVED,
            Auth::id(),
            ['donation_id' => $data['donation_id']],
            $data['contact_method'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(['record' => $donationOffer->fresh(self::WITH)]);
    }
}
