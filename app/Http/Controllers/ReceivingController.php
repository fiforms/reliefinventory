<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dock-side donation intake: donor, category, rough container count, a
 * free-text manifest, and optional shipment weight — fast entry that must
 * not block on item-level detail (that's Sorting's job). Per
 * receiving-sorting-workflow.md.
 */
class ReceivingController extends Controller
{
    /**
     * Open donations (received or sorting) for the Receiving dashboard.
     * Each record carries is_close_out_candidate (a donation down to
     * exactly one non-empty pallet, already in sorting — a state
     * condition, not a timer) so the list/detail RIForm view can surface
     * it without a second fetch.
     */
    public function index()
    {
        $open = Transaction::where('type', 'donation')
            ->whereHas('status', fn ($q) => $q->whereIn('name', [Transaction::STATUS_RECEIVED, Transaction::STATUS_SORTING]))
            ->with(['person', 'enteredBy', 'status', 'pallets'])
            ->orderBy('id', 'desc')
            ->get();

        $open->each(fn (Transaction $donation) => $donation->is_close_out_candidate = $this->isCloseOutCandidate($donation));

        return response()->json([
            'records' => $open,
            'templates' => [
                '_default' => [
                    'type' => 'donation',
                    'category' => 'donation',
                    'person_id' => null,
                    'container_count' => null,
                    'manifest' => null,
                    'manifest_weight_lbs' => null,
                    'comments' => null,
                ],
            ],
        ]);
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
    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:donation,equipment,supplies,other',
            'person_id' => 'nullable|exists:people,id',
            'container_count' => 'nullable|integer|min:0',
            'manifest' => 'nullable|string',
            'manifest_weight_lbs' => 'nullable|numeric|min:0',
            'comments' => 'nullable|string',
        ]);

        $donation = Transaction::create(array_merge($data, [
            'type' => 'donation',
            'person_id_user' => Auth::id(),
            'order_date' => now()->toDateString(),
            'status_id' => Transaction::statusId(
                $data['category'] === 'donation' ? Transaction::STATUS_RECEIVED : Transaction::STATUS_LOGGED
            ),
        ]));

        return response()->json(['record' => $donation->load(['person', 'status'])], 201);
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

        $data = $request->validate([
            'category' => 'required|in:donation,equipment,supplies,other',
            'person_id' => 'nullable|exists:people,id',
            'container_count' => 'nullable|integer|min:0',
            'manifest' => 'nullable|string',
            'manifest_weight_lbs' => 'nullable|numeric|min:0',
            'comments' => 'nullable|string',
        ]);

        if ($data['category'] !== $donation->category && $donation->pallets->isNotEmpty()) {
            return response()->json([
                'message' => 'Category can\'t be changed once pallets have been created for this intake.',
            ], 422);
        }

        $donation->update($data);

        return response()->json(['record' => $donation->fresh(['person', 'status', 'pallets'])]);
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
        $data = $request->validate(['count' => 'required|integer|min:1|max:200']);

        $pallets = DB::transaction(function () use ($donation, $data) {
            $created = [];
            for ($i = 0; $i < $data['count']; $i++) {
                $pallet = Pallet::create([
                    'kind' => 'R',
                    'status' => 'received',
                    'container_type' => 'pallet',
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
}
