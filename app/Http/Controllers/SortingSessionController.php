<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Models\Status;
use App\Models\Transaction;
use App\Support\PalletKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Scan-driven donation sorting sessions.
 *
 * Unlike the RIForm document model (edit in memory, save the whole record at
 * the end), sorting sessions are an event stream: the session (a donation
 * transaction) is created when sorting starts, each sorted line is committed
 * immediately as it is entered, and the session is marked complete at the
 * end. A crash or dropped connection never loses more than the line being
 * typed.
 */
class SortingSessionController extends Controller
{
    // Shared with Transaction's donation-lifecycle vocabulary — a session's
    // "in progress" state is exactly the donation's "sorting" status, not a
    // separate parallel concept.
    private const STATUS_IN_PROGRESS = Transaction::STATUS_SORTING;

    private const STATUS_COMPLETED = Transaction::STATUS_COMPLETE;

    private const WITH_RELATIONS = [
        'person',
        'enteredBy',
        'status',
        'itemLedgers.item.itemtype',
        'itemLedgers.pallet',
    ];

    private const LINE_VALIDATION = [
        'item_id' => 'required|exists:items,id',
        'qty' => 'required|integer|min:1',
        'disposition' => 'required|in:usable,outdated,trashed,diverted',
        'pallet_tag' => 'nullable|string',
    ];

    private function statusId(string $name): int
    {
        return Status::firstOrCreate(['name' => $name], ['description' => $name])->id;
    }

    /**
     * Normalize a scanned pallet tag ("P00000042", "p42", "42") to a pallet ID.
     */
    public static function palletIdFromTag(string $tag): ?int
    {
        $digits = preg_replace('/\D/', '', $tag);

        return $digits === '' ? null : (int) $digits;
    }

    /**
     * List open sessions (resumable), donations ready to pick up from
     * Receiving (not yet started), and recently completed ones.
     */
    public function index()
    {
        $inProgress = $this->statusId(self::STATUS_IN_PROGRESS);
        $received = $this->statusId(Transaction::STATUS_RECEIVED);
        $completed = $this->statusId(self::STATUS_COMPLETED);

        $open = Transaction::where('type', 'donation')
            ->where('status_id', $inProgress)
            ->with(self::WITH_RELATIONS)
            ->orderBy('id', 'desc')
            ->get();

        $receivable = Transaction::where('type', 'donation')
            ->where('status_id', $received)
            ->with(self::WITH_RELATIONS)
            ->orderBy('id')
            ->get();

        $recent = Transaction::where('type', 'donation')
            ->where('status_id', $completed)
            ->with(self::WITH_RELATIONS)
            ->orderBy('id', 'desc')
            ->limit(25)
            ->get();

        return response()->json([
            'open' => $open,
            'receivable' => $receivable,
            'recent' => $recent,
        ]);
    }

    /**
     * Start a sorting session — either picking up an existing donation
     * Receiving already created (the normal path), or starting fresh for
     * untagged/walk-in goods with no Receiving record (never block sorting
     * on a data problem).
     */
    public function store(Request $request)
    {
        $data = $request->validate(['donation_id' => 'nullable|exists:orderdonations,id']);

        if (! empty($data['donation_id'])) {
            $session = Transaction::where('type', 'donation')->findOrFail($data['donation_id']);
        } else {
            $session = Transaction::create([
                'type' => 'donation',
                'category' => 'donation',
                'person_id_user' => Auth::id(),
                'status_id' => $this->statusId(self::STATUS_IN_PROGRESS),
                'order_date' => now()->toDateString(),
            ]);
        }

        return response()->json([
            'record' => $session->load(self::WITH_RELATIONS),
        ], 201);
    }

    public function show($id)
    {
        $session = Transaction::where('type', 'donation')
            ->with(self::WITH_RELATIONS)
            ->findOrFail($id);

        return response()->json(['record' => $session]);
    }

    /**
     * Update session header fields (donor, comments) or complete/reopen it.
     */
    public function update(Request $request, $id)
    {
        $session = Transaction::where('type', 'donation')->findOrFail($id);

        $data = $request->validate([
            'person_id' => 'nullable|exists:people,id',
            'comments' => 'nullable|string',
            'completed' => 'nullable|boolean',
        ]);

        if (array_key_exists('completed', $data)) {
            $session->status_id = $this->statusId(
                $data['completed'] ? self::STATUS_COMPLETED : self::STATUS_IN_PROGRESS
            );
            unset($data['completed']);
        }

        $session->fill($data)->save();

        return response()->json([
            'record' => $session->load(self::WITH_RELATIONS),
        ]);
    }

    /**
     * Resolve a scanned pallet tag so the UI can show pallet context.
     * Sorting only ever works receiving (R) pallets — scanning any other
     * kind is rejected rather than silently accepted. The first scan of a
     * session auto-advances the pallet from "received" to "sorting"
     * (pallet-container-model: "Sorting auto-advances receiving pallets").
     */
    public function pallet(string $tag)
    {
        $id = self::palletIdFromTag($tag);
        $pallet = $id ? Pallet::with('location')->find($id) : null;

        if (! $pallet) {
            return response()->json([
                'message' => 'Unknown pallet tag: '.$tag,
            ], 404);
        }

        if ($pallet->kind !== PalletKind::RECEIVING) {
            return response()->json([
                'message' => 'That tag belongs to a '.(PalletKind::LABELS[$pallet->kind] ?? $pallet->kind).' pallet, not a Receiving pallet.',
            ], 422);
        }

        if ($pallet->status === 'received') {
            $pallet->transitionTo('sorting');
        }

        return response()->json(['record' => $pallet]);
    }

    /**
     * Append one sorted line to the session. Called once per line as the
     * sorter works, so entered data is never held only in the browser.
     */
    public function storeLine(Request $request, $id)
    {
        $session = Transaction::where('type', 'donation')->findOrFail($id);
        $data = $request->validate(self::LINE_VALIDATION);

        $palletId = null;
        if (! empty($data['pallet_tag'])) {
            $palletId = self::palletIdFromTag($data['pallet_tag']);
            $pallet = $palletId ? Pallet::find($palletId) : null;
            if (! $pallet || $pallet->kind !== PalletKind::RECEIVING) {
                return response()->json([
                    'errors' => ['pallet_tag' => ['Unknown receiving pallet tag.']],
                ], 422);
            }
        }

        $line = $session->itemLedgers()->create([
            'item_id' => $data['item_id'],
            'pallet_id' => $palletId,
            'qty_added' => $data['qty'],
            'disposition' => $data['disposition'],
        ]);

        return response()->json([
            'record' => $line->load(['item.itemtype', 'pallet']),
        ], 201);
    }

    /**
     * Correct a previously entered line.
     */
    public function updateLine(Request $request, $id, $lineId)
    {
        $session = Transaction::where('type', 'donation')->findOrFail($id);
        $line = $session->itemLedgers()->findOrFail($lineId);

        $data = $request->validate([
            'item_id' => 'nullable|exists:items,id',
            'qty' => 'nullable|integer|min:1',
            'disposition' => 'nullable|in:usable,outdated,trashed,diverted',
        ]);

        if (isset($data['qty'])) {
            $line->qty_added = $data['qty'];
        }
        if (isset($data['item_id'])) {
            $line->item_id = $data['item_id'];
        }
        if (isset($data['disposition'])) {
            $line->disposition = $data['disposition'];
        }
        $line->save();

        return response()->json([
            'record' => $line->load(['item.itemtype', 'pallet']),
        ]);
    }

    /**
     * Remove a mistakenly entered line.
     */
    public function destroyLine($id, $lineId)
    {
        $session = Transaction::where('type', 'donation')->findOrFail($id);
        $session->itemLedgers()->findOrFail($lineId)->delete();

        return response()->json(['success' => true]);
    }
}
