<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Order Filling/Picking — the first place item_ledgers.qty_subtracted ever
 * becomes real in this app (previously always 0 in production). Follows
 * OrderController's event-stream pattern: no separate "session" model, the
 * order (Transaction) itself is the session, exactly like donation sorting
 * uses the donation Transaction as its session (see SortingSessionController
 * — there is no SortingSession table either).
 *
 * One shared backend serves two capture modes, not two systems: a live-scan
 * flow and a paper/batch-print flow both call the exact same fill-record
 * endpoints below — the only difference is whether the qty is typed in at
 * the moment of picking or transcribed afterward from a printed sheet.
 *
 * A fill record *is* an ItemLedger row (qty_subtracted, linked to the order
 * line it satisfies via order_line_id) — append-only, multiple allowed per
 * line, matching Sorting's own ledger-row-per-scan model. "Filled so far"
 * for a line is the sum of its fill records, never an overwritten total.
 */
class OrderFillingController extends Controller
{
    private const QUEUE_RELATIONS = [
        'person',
        'status',
        'orderLines.itemtype.unit',
        'orderLines.itemLedgers.item.itemtype',
    ];

    private const FILL_VALIDATION = [
        'item_id' => 'required|exists:items,id',
        'qty' => 'required|integer|min:0',
    ];

    /**
     * Fill-record writes are only allowed while an order is actively being
     * filled — before Ready to Fill/New Order, there's nothing to fill yet;
     * after Filled, the record is closed. Mirrors OrderController's
     * rejectIfLocked, inverted (this guards a narrower window, not "any
     * non-New-Order status").
     */
    private function rejectUnlessFilling(Transaction $order)
    {
        if ($order->status?->name !== Transaction::STATUS_FILLING) {
            abort(response()->json([
                'message' => 'This order is not currently being filled.',
            ], 409));
        }
    }

    /**
     * Orders ready to be worked (Ready to Fill) and orders currently in
     * progress (Filling), each with lines + their fill records loaded so
     * the panel can compute filled-so-far and allocation totals client-side.
     */
    public function index()
    {
        $readyToFill = Transaction::where('type', 'order')
            ->where('status_id', Transaction::statusId(Transaction::STATUS_READY_TO_FILL))
            ->with(self::QUEUE_RELATIONS)
            ->orderBy('id')
            ->get();

        $filling = Transaction::where('type', 'order')
            ->where('status_id', Transaction::statusId(Transaction::STATUS_FILLING))
            ->with(self::QUEUE_RELATIONS)
            ->orderBy('status_changed_at')
            ->get();

        return response()->json([
            'ready_to_fill' => $readyToFill,
            'filling' => $filling,
        ]);
    }

    /**
     * Start filling one order directly (the live/manual capture path) —
     * Ready to Fill -> Filling.
     */
    public function start($id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        if ($order->status?->name !== Transaction::STATUS_READY_TO_FILL) {
            abort(response()->json([
                'message' => 'Only orders Ready to Fill can be started.',
            ], 409));
        }

        $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);

        return response()->json(['record' => $order->load(self::QUEUE_RELATIONS)]);
    }

    /**
     * Batch select+lock for the paper path: every order currently Ready to
     * Fill moves to Filling atomically, and the caller renders the PDF for
     * exactly those IDs next (a separate, pure-render GET — see
     * pickSheetsPdf) so a reload/reprint never re-triggers this mutation.
     */
    public function printPickSheets()
    {
        return DB::transaction(function () {
            $ids = Transaction::where('type', 'order')
                ->where('status_id', Transaction::statusId(Transaction::STATUS_READY_TO_FILL))
                ->lockForUpdate()
                ->orderBy('id')
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                // Query-builder mass update bypasses Transaction::booted()'s
                // saving hook, so status_changed_at must be set explicitly
                // here — it is NOT auto-stamped for this path otherwise.
                Transaction::whereIn('id', $ids)->update([
                    'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
                    'status_changed_at' => now(),
                ]);
            }

            return response()->json(['order_ids' => $ids]);
        });
    }

    /**
     * Pure render, no mutation — safe to reload or reprint. Covers both the
     * batch print (ids = everything printPickSheets just locked) and a
     * single already-Filling order's reprint link.
     */
    public function pickSheetsPdf(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:orderdonations,id',
        ]);

        $orders = Transaction::where('type', 'order')
            ->whereIn('id', $data['ids'])
            ->with(['person', 'orderLines.itemtype.unit'])
            ->get()
            ->sortBy(fn ($order) => array_search($order->id, $data['ids']))
            ->values();

        $generatedAt = now();

        return Pdf::view('reports.pick-sheets', ['orders' => $orders, 'generatedAt' => $generatedAt])
            ->driver('weasyprint')
            ->format('letter')
            ->name('pick-sheets-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    /**
     * Record one fill against one requested line. item_id must belong to
     * the line's itemtype — the specific catalog variant is chosen here, at
     * fill time, not at request time (same reasoning as packagetype_id
     * being nullable on OrderLine).
     */
    public function storeFill(Request $request, $id, $lineId)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectUnlessFilling($order);
        $line = $order->orderLines()->findOrFail($lineId);
        $data = $request->validate(self::FILL_VALIDATION);

        $item = Item::findOrFail($data['item_id']);
        if ((int) $item->itemtype_id !== (int) $line->itemtype_id) {
            return response()->json([
                'errors' => ['item_id' => ["That item does not match this line's item type."]],
            ], 422);
        }

        $fill = $order->itemLedgers()->create([
            'item_id' => $data['item_id'],
            'order_line_id' => $line->id,
            'qty_subtracted' => $data['qty'],
            'disposition' => 'usable',
            'person_id_user' => Auth::id(),
        ]);

        return response()->json([
            'record' => $fill->load(['item.itemtype']),
        ], 201);
    }

    public function updateFill(Request $request, $id, $lineId, $fillId)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectUnlessFilling($order);
        $line = $order->orderLines()->findOrFail($lineId);
        $fill = $line->itemLedgers()->findOrFail($fillId);

        $data = $request->validate([
            'item_id' => 'sometimes|required|exists:items,id',
            'qty' => 'sometimes|required|integer|min:0',
        ]);

        if (isset($data['item_id'])) {
            $item = Item::findOrFail($data['item_id']);
            if ((int) $item->itemtype_id !== (int) $line->itemtype_id) {
                return response()->json([
                    'errors' => ['item_id' => ["That item does not match this line's item type."]],
                ], 422);
            }
            $fill->item_id = $data['item_id'];
        }
        if (isset($data['qty'])) {
            $fill->qty_subtracted = $data['qty'];
        }
        $fill->save();

        return response()->json(['record' => $fill->load(['item.itemtype'])]);
    }

    public function destroyFill($id, $lineId, $fillId)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectUnlessFilling($order);
        $line = $order->orderLines()->findOrFail($lineId);
        $line->itemLedgers()->findOrFail($fillId)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Filling -> Filled, once every requested line has at least one fill
     * record (even a deliberate zero-quantity one — "don't have this,
     * filled 0"). Mirrors the legacy Apps Script system's isPullSheetReady()
     * rule: every line accounted for, zero counts as accounted for.
     */
    public function completeFilling(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        if ($order->status?->name !== Transaction::STATUS_FILLING) {
            abort(response()->json([
                'message' => 'Only orders currently Filling can be completed.',
            ], 409));
        }

        if ($order->orderLines()->doesntHave('itemLedgers')->exists()) {
            return response()->json([
                'message' => 'Every line needs at least one fill record (even a zero-quantity one) before completing.',
            ], 422);
        }

        $data = $request->validate([
            'pallet_count' => 'nullable|integer|min:1',
        ]);

        $order->update([
            'status_id' => Transaction::statusId(Transaction::STATUS_FILLED),
            'pallet_count' => $data['pallet_count'] ?? null,
        ]);

        return response()->json(['record' => $order->load(self::QUEUE_RELATIONS)]);
    }
}
