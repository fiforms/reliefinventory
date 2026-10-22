<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\OrderLine;
use App\Models\Pallet;
use App\Models\Transaction;
use App\Services\PalletStock;
use App\Services\PickPlanner;
use App\Support\PalletKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

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
        // Optional: the warehouse pallet this was picked from (scanned tag or number). Keeps per-pallet
        // quantities, and so "where is it", current; omitting it just draws down the unassigned pool.
        'pallet_tag' => 'nullable|string',
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

        return $this->pickSheetsBuilder($data['ids']);
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function pickSheetsBuilder(array $ids): PdfBuilder
    {
        $orders = Transaction::where('type', 'order')
            ->whereIn('id', $ids)
            ->with(['person', 'orderLines.itemtype.unit', 'orderLines.itemLedgers'])
            ->get()
            ->sortBy(fn ($order) => array_search($order->id, $ids))
            ->values();

        // Planned across the whole batch in print order, so two sheets are never told to take the same cases.
        $plans = self::planLines($orders->flatMap->orderLines);

        $generatedAt = now();

        return Pdf::view('reports.pick-sheets', ['orders' => $orders, 'plans' => $plans, 'generatedAt' => $generatedAt])
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

        $pallet = null;
        if (! empty($data['pallet_tag'])) {
            $palletId = SortingSessionController::palletIdFromTag($data['pallet_tag']);
            $pallet = $palletId ? Pallet::find($palletId) : null;
            if (! $pallet || $pallet->kind !== PalletKind::WAREHOUSE) {
                return response()->json(['errors' => ['pallet_tag' => ['Unknown Warehouse pallet tag.']]], 422);
            }
            // A sealed pallet can't be picked *from*, but it can leave whole: the pick must take everything on it.
            $onPallet = PalletStock::palletTotal($pallet->id);
            $takesWholeSealed = $pallet->status === 'sealed' && $onPallet === (int) $data['qty']
                && (int) PalletStock::quantities([$pallet->id], [(int) $item->id])->sum('qty') === $onPallet; // all of it is this item
            if ($pallet->status !== 'open' && ! $takesWholeSealed) {
                return response()->json(['errors' => ['pallet_tag' => [$pallet->status === 'sealed'
                    ? "{$pallet->tag} is sealed — open it first to pick part of it (or take the whole pallet)."
                    : "{$pallet->tag} is {$pallet->status} and can't be picked from."]]], 422);
            }
        }

        $warning = null;
        $fills = DB::transaction(function () use ($order, $data, $line, $pallet, &$warning) {
            $itemId = (int) $data['item_id'];
            $qty = (int) $data['qty'];

            // [pallet|null, qty] chunks. A named pallet takes the whole pick. Otherwise the unassigned pool (the sorting
            // area) goes first, and any overflow comes off the oldest pallets holding the item — so unassigned never
            // goes negative just because a picker didn't scan a pallet. Only a true over-pick leaves a remainder.
            $chunks = [];
            if ($pallet) {
                $chunks[] = [$pallet, $qty];
            } else {
                $overflow = $qty - max(0, PalletStock::unassigned($itemId));
                if ($overflow > 0) {
                    $holding = PalletStock::quantities(null, [$itemId])->filter(fn ($r) => $r['qty'] > 0)->sortBy('pallet_id');
                    $pallets = Pallet::with('location:id,code')->whereIn('id', $holding->pluck('pallet_id'))->get()->keyBy('id');
                    foreach ($holding as $row) {
                        $candidate = $pallets[$row['pallet_id']] ?? null;
                        if ($overflow <= 0 || ! $candidate || $candidate->status !== 'open') {
                            continue; // only open pallets are drawn from, oldest first
                        }
                        $take = min($overflow, $row['qty']);
                        $chunks[] = [$candidate, $take];
                        $overflow -= $take;
                    }

                    // Still short, but sealed pallets hold the item: the picker has to open one — never silently.
                    $sealed = $holding->filter(fn ($r) => ($pallets[$r['pallet_id']] ?? null)?->status === 'sealed');
                    if ($overflow > 0 && $sealed->isNotEmpty()) {
                        abort(response()->json([
                            'message' => "Only ".($qty - $overflow)." of {$qty} can come from the sorting area and open pallets. Open a sealed pallet to pick the other {$overflow}.",
                            'sealed_pallets' => $sealed->map(fn ($r) => [
                                'id' => $r['pallet_id'], 'tag' => $pallets[$r['pallet_id']]->tag,
                                'location' => $pallets[$r['pallet_id']]->location?->code, 'qty' => $r['qty'],
                            ])->values(),
                        ], 422));
                    }
                }
                $fromPallets = array_sum(array_column($chunks, 1));
                if ($qty - $fromPallets > 0 || ! $chunks) {
                    array_unshift($chunks, [null, $qty - $fromPallets]);
                }
                if ($overflow > 0) {
                    $warning = "That's {$overflow} more than the database says is in stock — check the count.";
                }
            }

            $fills = [];
            foreach ($chunks as [$chunkPallet, $chunkQty]) {
                $fills[] = $order->itemLedgers()->create([
                    'item_id' => $itemId,
                    'order_line_id' => $line->id,
                    'stock_pallet_id' => $chunkPallet?->id,
                    'qty_subtracted' => $chunkQty,
                    'disposition' => 'usable',
                    'person_id_user' => Auth::id(),
                ]);

                if ($chunkPallet) {
                    if ($chunkPallet->status === 'sealed') {
                        $chunkPallet->transitionTo('open', null, 'Taken whole for an order'); // then emptied just below
                    }
                    if ($pallet && PalletStock::quantities([$pallet->id], [$itemId])->sum('qty') < 0) {
                        $warning = "{$pallet->tag} was only recorded as holding ".(PalletStock::quantities([$pallet->id], [$itemId])->sum('qty') + $chunkQty).' of this item — check the count.';
                    }
                    if (PalletStock::palletTotal($chunkPallet->id) <= 0 && $chunkPallet->status !== 'empty') {
                        $chunkPallet->transitionTo('empty', null, 'Picked clean');
                    }
                }
            }

            return $fills;
        });

        foreach ($fills as $fill) {
            $fill->load(['item.itemtype']);
        }

        return response()->json([
            'record' => $fills[0],
            'records' => $fills,
            'warning' => $warning,
        ], 201);
    }

    /**
     * Suggested pick for every line still to pick on an order — see PickPlanner (pool, whole sealed pallets, open pallets,
     * then the oldest sealed one) — in walking order along the pick path. Recomputed from live stock on every call.
     */
    public function plan($id)
    {
        $order = Transaction::where('type', 'order')->with(['orderLines.itemLedgers'])->findOrFail($id);

        return response()->json(['lines' => self::planLines($order->orderLines)]);
    }

    /**
     * @param  iterable<OrderLine>  $lines  lines across one or more orders, planned in the order given
     * @return array<int, array> line id => plan (steps, short, first_sequence)
     */
    public static function planLines(iterable $lines): array
    {
        $wanted = [];
        foreach ($lines as $line) {
            $need = $line->qty_to_pick - (int) $line->itemLedgers->sum('qty_subtracted');
            if ($need > 0) {
                $wanted[$line->id] = ['itemtype_id' => $line->itemtype_id, 'need' => $need];
            }
        }

        return PickPlanner::planMany($wanted);
    }

    /** Open a sealed warehouse pallet so it can be picked from. */
    public function openPallet($id)
    {
        $pallet = Pallet::findOrFail($id);
        if ($pallet->kind !== PalletKind::WAREHOUSE) {
            return response()->json(['message' => 'Only Warehouse pallets are opened for picking.'], 422);
        }
        if ($pallet->status === 'sealed') {
            $pallet->transitionTo('open', null, 'Opened for picking');
        } elseif ($pallet->status !== 'open') {
            return response()->json(['message' => "{$pallet->tag} is {$pallet->status} and can't be opened."], 422);
        }

        return response()->json(['record' => ['id' => $pallet->id, 'tag' => $pallet->tag, 'status' => $pallet->fresh()->status]]);
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

        return DB::transaction(function () use ($fill) {
            $fill->save();

            // A fill picked from a pallet must not leave that pallet holding less than nothing of the item.
            if ($fill->stock_pallet_id) {
                $left = (int) PalletStock::quantities([$fill->stock_pallet_id], [(int) $fill->item_id])->sum('qty');
                if ($left < 0) {
                    DB::rollBack();

                    return response()->json(['errors' => ['qty' => ['That pallet does not hold that much of this item.']]], 422);
                }
                $this->syncPalletEmptiness($fill->stock_pallet_id);
            }

            return response()->json(['record' => $fill->load(['item.itemtype'])]);
        });
    }

    /**
     * A pallet is "empty" exactly when nothing is recorded on it. Picking clears it; undoing or shrinking a pick can put
     * stock back, so it must come back as an open pallet rather than stay empty with goods on it.
     */
    private function syncPalletEmptiness(int $palletId): void
    {
        $pallet = Pallet::find($palletId);
        if (! $pallet || $pallet->kind !== PalletKind::WAREHOUSE) {
            return;
        }
        $total = PalletStock::palletTotal($palletId);
        if ($total <= 0 && $pallet->status !== 'empty') {
            $pallet->transitionTo('empty', null, 'Picked clean');
        } elseif ($total > 0 && $pallet->status === 'empty') {
            $pallet->transitionTo('open', null, 'Pick changed — stock is back on the pallet');
        }
    }

    public function destroyFill($id, $lineId, $fillId)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectUnlessFilling($order);
        $line = $order->orderLines()->findOrFail($lineId);
        $fill = $line->itemLedgers()->findOrFail($fillId);
        $palletId = $fill->stock_pallet_id;

        DB::transaction(function () use ($fill, $palletId) {
            $fill->delete();
            if ($palletId) {
                $this->syncPalletEmptiness($palletId);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Save approved (allocated) quantities from the Review Allocation panel
     * (feedback #50). Only for orders still Ready to Fill — once a pick
     * sheet is printed or filling starts, the picker's number is fixed.
     * qty_approved = null clears an approval (back to the full request).
     * Never above what was requested; qty_requested itself is untouched.
     */
    public function approveAllocation(Request $request)
    {
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer|distinct|exists:orderlines,id',
            'lines.*.qty_approved' => 'present|nullable|integer|min:0',
        ]);

        return DB::transaction(function () use ($data) {
            $readyToFill = Transaction::statusId(Transaction::STATUS_READY_TO_FILL);
            $lines = OrderLine::whereIn('id', collect($data['lines'])->pluck('id'))
                ->with('transaction')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $errors = [];
            foreach ($data['lines'] as $i => $entry) {
                $line = $lines[$entry['id']];
                if ($line->transaction?->type !== 'order' || (int) $line->transaction->status_id !== $readyToFill) {
                    $errors["lines.$i.id"] = ['Approved quantities can only be changed while an order is Ready to Fill.'];
                } elseif ($entry['qty_approved'] !== null && $entry['qty_approved'] > $line->qty_requested) {
                    $errors["lines.$i.qty_approved"] = ['Approved quantity can\'t be more than was requested ('.$line->qty_requested.').'];
                }
            }
            if ($errors) {
                return response()->json(['message' => 'Some approved quantities could not be saved.', 'errors' => $errors], 422);
            }

            foreach ($data['lines'] as $entry) {
                $line = $lines[$entry['id']];
                $line->qty_approved = $entry['qty_approved'];
                $line->approved_by_person_id = $entry['qty_approved'] === null ? null : Auth::id();
                $line->approved_at = $entry['qty_approved'] === null ? null : now();
                $line->save();
            }

            return response()->json(['records' => $lines->values()->map->only(['id', 'qty_requested', 'qty_approved', 'qty_to_pick'])]);
        });
    }

    /**
     * Filling -> Filled, once every line with something to pick has at least
     * one fill record (even a deliberate zero-quantity one — "don't have
     * this, filled 0"). Mirrors the legacy Apps Script system's
     * isPullSheetReady() rule: every line accounted for, zero counts as
     * accounted for. A line approved at 0 has nothing to pick, so it needs
     * no fill record.
     *
     * If nothing at all was picked (every fill is 0, or every line was
     * approved at 0), the order goes to Closed - Unfulfilled instead —
     * terminal, never offered to Shipping (feedback #53).
     */
    public function completeFilling(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        if ($order->status?->name !== Transaction::STATUS_FILLING) {
            abort(response()->json([
                'message' => 'Only orders currently Filling can be completed.',
            ], 409));
        }

        $unaccounted = $order->orderLines()->withCount('itemLedgers')->get()
            ->filter(fn (OrderLine $line) => $line->qty_to_pick > 0 && $line->item_ledgers_count === 0);
        if ($unaccounted->isNotEmpty()) {
            return response()->json([
                'message' => 'Every line needs at least one fill record (even a zero-quantity one) before completing.',
            ], 422);
        }

        $data = $request->validate([
            'pallet_count' => 'nullable|integer|min:1',
        ]);

        $nothingPicked = (int) $order->itemLedgers()->whereNotNull('order_line_id')->sum('qty_subtracted') === 0;

        $order->update($nothingPicked ? [
            'status_id' => Transaction::statusId(Transaction::STATUS_CLOSED_UNFULFILLED),
            'pallet_count' => null,
        ] : [
            'status_id' => Transaction::statusId(Transaction::STATUS_FILLED),
            'pallet_count' => $data['pallet_count'] ?? null,
        ]);

        return response()->json(['record' => $order->load(self::QUEUE_RELATIONS)]);
    }
}
