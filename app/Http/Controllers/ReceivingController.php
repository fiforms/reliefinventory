<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DonationOffer;
use App\Models\Pallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Dock-side donation intake: donor, category, rough container count, a
 * free-text manifest, and optional shipment weight — fast entry that must
 * not block on item-level detail (that's Sorting's job). Per
 * receiving-sorting-workflow.md.
 */
class ReceivingController extends Controller
{
    private const VALIDATION_RULES = [
        'category' => 'required|in:donation,equipment,supplies,other',
        'category_other' => 'nullable|required_if:category,other|string|max:255',
        'person_id' => 'nullable|exists:people,id',
        'contact_person_id' => 'nullable|exists:people,id',
        'donor_identification_pending' => 'nullable|boolean',
        'order_date' => 'nullable|date',
        'container_count' => 'nullable|integer|min:0',
        'manifest' => 'nullable|string',
        'manifest_weight_lbs' => 'nullable|numeric|min:0',
        'driver_id' => 'nullable|exists:drivers,id',
        'arrival_method' => 'nullable|in:semi,box_truck,personal_vehicle,delivery_truck,trailer,other',
        'arrival_method_other' => 'nullable|required_if:arrival_method,other|string|max:255',
        'carrier' => 'nullable|string|max:255',
        // ['pallet'] (exclusive) or any subset of box/bag/tote/loose — see
        // the pallet-exclusivity check in store()/update().
        'container_types' => 'nullable|array',
        'container_types.*' => 'in:pallet,box,bag,tote,loose',
        // Per-type quantity, e.g. {"box": 4, "tote": 2} — keys aren't
        // validated against container_types here (the frontend keeps them
        // in sync); container_count is computed client-side as their sum.
        'container_type_counts' => 'nullable|array',
        'container_type_counts.*' => 'nullable|integer|min:0',
        'source_address' => 'nullable|string',
        'source_city' => 'nullable|string|max:255',
        'source_state' => 'nullable|string|max:2',
        'source_zip' => 'nullable|string|max:10',
        'comments' => 'nullable|string',
        'quick_sort_candidate' => 'nullable|boolean',
    ];

    /**
     * Open donations (received or sorting) for the Receiving dashboard.
     * Each record carries is_close_out_candidate (a donation down to
     * exactly one non-empty pallet, already in sorting — a state
     * condition, not a timer) so the list/detail RIForm view can surface
     * it without a second fetch.
     */
    public function index()
    {
        // A donation flagged donor_identification_pending stays here even
        // once it's Complete — that flag exists precisely so it can be
        // found later, and it would otherwise silently age out of both this
        // list and Sorting's once enough other donations pass through.
        $open = Transaction::where('type', 'donation')
            ->where(function ($q) {
                $q->whereHas('status', fn ($q2) => $q2->whereIn('name', [Transaction::STATUS_RECEIVED, Transaction::STATUS_SORTING]))
                    ->orWhere('donor_identification_pending', true);
            })
            ->with(['person', 'contactPerson', 'driver', 'enteredBy', 'status', 'pallets.contentItem'])
            ->orderBy('id', 'desc')
            ->get();

        // Non-donation intakes (equipment/supplies/other, status Logged) used
        // to vanish from this list the moment they were saved; keep the most
        // recent ones visible so the record is findable and editable.
        $logged = Transaction::where('type', 'donation')
            ->whereHas('status', fn ($q) => $q->where('name', Transaction::STATUS_LOGGED))
            ->with(['person', 'contactPerson', 'driver', 'enteredBy', 'status', 'pallets.contentItem'])
            ->orderBy('id', 'desc')
            ->limit(25)
            ->get();

        $records = $open->concat($logged)->sortByDesc('id')->values();
        $records->each(fn (Transaction $donation) => $donation->is_close_out_candidate = $this->isCloseOutCandidate($donation));

        return response()->json([
            'records' => $records,
            'templates' => [
                '_default' => [
                    'type' => 'donation',
                    'category' => 'donation',
                    'category_other' => null,
                    'person_id' => null,
                    'contact_person_id' => null,
                    'donor_identification_pending' => false,
                    'order_date' => now()->toDateString(),
                    'container_count' => null,
                    'manifest' => null,
                    'manifest_weight_lbs' => null,
                    'driver_id' => null,
                    'arrival_method' => null,
                    'arrival_method_other' => null,
                    'carrier' => null,
                    'container_types' => null,
                    'container_type_counts' => (object) [],
                    'source_address' => null,
                    'source_city' => null,
                    'source_state' => null,
                    'source_zip' => null,
                    'comments' => null,
                    'photo_path' => null,
                    'quick_sort_candidate' => null,
                ],
            ],
        ]);
    }

    /**
     * Pallets is a single, exclusive choice on Receiving.vue's "How did
     * this arrive?" question — box/bag/tote/loose is where the multi-select
     * lives. Enforced here too since the frontend UI already prevents it,
     * but a client bug or direct API call shouldn't silently store nonsense.
     */
    private function containerTypesError(array $data): ?string
    {
        $types = $data['container_types'] ?? null;
        if ($types && in_array('pallet', $types) && count($types) > 1) {
            return 'Pallets can\'t be combined with other container types.';
        }

        return null;
    }

    private function isCloseOutCandidate(Transaction $donation): bool
    {
        $notEmpty = $donation->pallets->where('status', '!=', 'empty');

        return $notEmpty->count() === 1 && $notEmpty->first()->status === 'sorting';
    }

    /**
     * Record a new intake. Only "donation" category proceeds into the
     * sorting pipeline (received status); other categories are logged for
     * the manifest audit trail but don't get a stored donation lifecycle.
     */
    /**
     * A donation offered by phone ahead of arrival (see DonationOffer) can
     * be matched right here at intake instead of requiring a separate trip
     * to the offers worklist afterward. Validated ad hoc, not part of
     * VALIDATION_RULES, so it never mass-assigns onto the Transaction.
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $offerId = $request->validate(['donation_offer_id' => 'nullable|exists:donation_offers,id'])['donation_offer_id'] ?? null;

        if ($error = $this->containerTypesError($data)) {
            return response()->json(['message' => $error], 422);
        }

        $offer = null;
        if ($offerId) {
            $offer = DonationOffer::findOrFail($offerId);
            if ($offer->status !== DonationOffer::STATUS_PENDING) {
                return response()->json(['message' => 'That donation offer is not pending and can\'t be matched.'], 422);
            }
        }

        $donation = DB::transaction(function () use ($data, $offer) {
            $donation = Transaction::create(array_merge($data, [
                'type' => 'donation',
                'person_id_user' => Auth::id(),
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'status_id' => Transaction::statusId(
                    $data['category'] === 'donation' ? Transaction::STATUS_RECEIVED : Transaction::STATUS_LOGGED
                ),
            ]));

            if ($offer) {
                $offer->transitionTo(DonationOffer::STATUS_RECEIVED, Auth::id(), ['donation_id' => $donation->id]);
            }

            return $donation;
        });

        return response()->json(['record' => $donation->load(['person', 'contactPerson', 'driver', 'status'])], 201);
    }

    /**
     * Edit intake details after the fact (donor, counts, manifest text,
     * etc). Category can't change once pallets exist — switching a
     * donation-category record away from "donation" (or vice versa) after
     * pallets are already tied to it would desync the record from the
     * pipeline it's actually in.
     */
    public function update(Request $request, $id)
    {
        $donation = Transaction::where('type', 'donation')->with('pallets')->findOrFail($id);

        $data = $request->validate(self::VALIDATION_RULES);

        if ($error = $this->containerTypesError($data)) {
            return response()->json(['message' => $error], 422);
        }

        if ($data['category'] !== $donation->category && $donation->pallets->isNotEmpty()) {
            return response()->json([
                'message' => 'Category can\'t be changed once pallets have been created for this intake.',
            ], 422);
        }

        // Recategorizing (only possible pre-pallets, per the guard above)
        // must also re-derive the lifecycle status — an "other" intake edited
        // to "donation" has to enter the sorting pipeline as Received, and a
        // "donation" edited away must leave it as Logged.
        if ($data['category'] !== $donation->category) {
            $data['status_id'] = Transaction::statusId(
                $data['category'] === 'donation' ? Transaction::STATUS_RECEIVED : Transaction::STATUS_LOGGED
            );
        }

        $donation->update($data);

        return response()->json(['record' => $donation->fresh(['person', 'contactPerson', 'driver', 'status', 'pallets.contentItem'])]);
    }

    /**
     * Remove an intake record entered in error. Blocked once pallets exist
     * — deleting would just null out their orderdonation_id (FK is
     * nullOnDelete) and silently orphan them from their donation instead
     * of actually cleaning anything up.
     */
    public function destroy($id)
    {
        $donation = Transaction::where('type', 'donation')->with('pallets')->findOrFail($id);

        if ($donation->pallets->isNotEmpty()) {
            return response()->json([
                'message' => 'This intake already has pallets created for it and can\'t be deleted.',
            ], 422);
        }

        if ($donation->photo_path) {
            Storage::disk('local')->delete($donation->photo_path);
        }

        $donation->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Create R pallets for this donation and link them, so they show up on
     * the sorting floor as "waiting to be sorted". A separate action (not
     * automatic on store()) so the dock can decide how many labels to print
     * once containers are actually counted, not just estimated.
     */
    public function createPallets(Request $request, $id)
    {
        $donation = Transaction::where('type', 'donation')->findOrFail($id);
        $data = $request->validate([
            'count' => 'required|integer|min:1|max:200',
            // Pallet/gaylord for palletized loads; box/bag/tote lets a
            // non-palletized arrival still get a printable, trackable label.
            'container_type' => 'nullable|in:pallet,gaylord,box,bag,tote',
        ]);

        // Deliberately no content_description/content_item_id here anymore
        // — identifying what's actually in a container means opening it,
        // which is sorting's job, not receiving's (see quick_sort_candidate
        // on the donation for the receiving-level equivalent: a rough,
        // visible-only judgment call instead of per-pallet cataloging).
        $pallets = DB::transaction(function () use ($donation, $data) {
            $created = [];
            for ($i = 0; $i < $data['count']; $i++) {
                $pallet = Pallet::create([
                    'kind' => 'R',
                    'status' => 'received',
                    'container_type' => $data['container_type'] ?? 'pallet',
                    'donor_person_id' => $donation->person_id,
                    'orderdonation_id' => $donation->id,
                    'datepacked' => now()->toDateString(),
                ]);
                $pallet->statuses()->create(['status' => 'received']);
                $created[] = $pallet;
            }

            return $created;
        });

        return response()->json(['records' => $pallets], 201);
    }

    /**
     * Daily close-out: correct the one forgotten pallet to "empty", which
     * rolls the donation to complete via the normal pallet-driven sync.
     */
    public function closeOut($id)
    {
        $donation = Transaction::where('type', 'donation')->with('pallets')->findOrFail($id);

        if (! $this->isCloseOutCandidate($donation)) {
            return response()->json([
                'message' => 'This donation is not a close-out candidate (must have exactly one non-empty pallet, already in sorting).',
            ], 422);
        }

        $donation->pallets->where('status', '!=', 'empty')->first()->transitionTo('empty', null, 'Closed out via daily close-out review.');

        $fresh = $donation->fresh(['pallets', 'status']);
        $fresh->is_close_out_candidate = $this->isCloseOutCandidate($fresh);

        return response()->json(['record' => $fresh]);
    }

    /**
     * A single reference photo of the shipment/load — replaces any existing
     * one for this intake (one photo, not a gallery). Stored the same way as
     * FeedbackReport screenshots.
     */
    public function uploadPhoto(Request $request, $id)
    {
        $donation = Transaction::where('type', 'donation')->findOrFail($id);
        $request->validate(['photo' => 'required|image|max:8192']);

        if ($donation->photo_path) {
            Storage::disk('local')->delete($donation->photo_path);
        }

        $donation->update(['photo_path' => $request->file('photo')->store('receiving-photos', 'local')]);

        return response()->json(['record' => $donation->fresh()]);
    }

    public function photo($id)
    {
        $donation = Transaction::where('type', 'donation')->findOrFail($id);
        abort_unless($donation->photo_path, 404);
        abort_unless(Storage::disk('local')->exists($donation->photo_path), 404);

        return Storage::disk('local')->response($donation->photo_path);
    }
}
