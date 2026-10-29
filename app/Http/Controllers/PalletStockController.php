<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\Pallet;
use App\Models\PalletStockAssignment;
use App\Models\Transaction;
use App\Services\PalletStock;
use App\Support\PalletKind;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Putting stock on warehouse (W) pallets. The core flow is one step as a pallet leaves the sorting area: scan (or
 * create) the W pallet, enter what is on it, pick where it is going — it is recorded sealed at that location. Quantities
 * come out of the pooled "unassigned" stock, so a pallet can never hold more than the ledger says exists.
 */
class PalletStockController extends Controller
{
    /** Why someone is correcting a pallet's contents by hand. */
    public const ADJUSTMENT_REASONS = [
        'shrinkage' => 'Shrinkage',
        'damaged' => 'Damaged items',
        'correcting_count' => 'Correcting count',
        'correcting_item_type' => 'Correcting item type',
        'other' => 'Other',
    ];

    public function contents($id)
    {
        $pallet = Pallet::with('location:id,code')->findOrFail($id);

        return response()->json(['record' => $this->payload($pallet)]);
    }

    /**
     * What is still in the sorting area: on-hand not yet put on a pallet, per item, biggest first. It is the
     * forklift driver's pick-up list. (Older stock that predates pallet tracking shows here too, until it's palletized.)
     */
    public function staging()
    {
        $onHand = DB::table('item_ledgers')->groupBy('item_id')
            ->selectRaw("item_id, SUM(CASE WHEN COALESCE(disposition, 'usable') = 'usable' THEN COALESCE(qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(qty_subtracted, 0)) as qty")
            ->pluck('qty', 'item_id');
        $onPallets = PalletStock::quantities()->groupBy('item_id')->map(fn ($rows) => (int) $rows->sum('qty'));

        $free = $onHand->map(fn ($qty, $itemId) => (int) $qty - ($onPallets[$itemId] ?? 0))->filter(fn ($qty) => $qty > 0)->sortDesc();
        $items = Item::with('itemtype:id,name')->whereIn('id', $free->keys())->get()->keyBy('id');

        $expiries = $this->stagingExpiries($free);

        return response()->json(['records' => $free->map(fn ($qty, $itemId) => [
            'item_id' => $itemId,
            'name' => $items[$itemId]->description ?? $items[$itemId]->itemtype?->name ?? '#'.$itemId,
            'qty' => $qty,
            'expires_on' => $expiries[$itemId] ?? null,
        ])->values()]);
    }

    /**
     * Best guess at the earliest expiration date of what is still in the sorting area, per item. Stock is pooled, so
     * which sorted lines the remaining units came from is unknowable; assume the oldest leave first (FIFO) and look
     * only at the newest usable additions that add up to the free quantity. Null when none of those carry a date.
     * It is only a suggestion for the Fill Pallet date box, which the driver can overwrite.
     *
     * @param  Collection<int, int>  $free  item_id => free quantity
     * @return array<int, string>
     */
    private function stagingExpiries($free): array
    {
        if ($free->isEmpty()) {
            return [];
        }
        $rows = DB::table('item_ledgers')->whereIn('item_id', $free->keys())
            ->whereRaw("COALESCE(disposition, 'usable') = 'usable'")->where('qty_added', '>', 0)->whereNull('stock_pallet_id')
            ->orderByDesc('id')->get(['item_id', 'qty_added', 'expires_on'])->groupBy('item_id');

        $out = [];
        foreach ($free as $itemId => $qty) {
            $covered = 0;
            foreach ($rows[$itemId] ?? [] as $row) {
                if ($row->expires_on && (! isset($out[$itemId]) || $row->expires_on < $out[$itemId])) {
                    $out[$itemId] = $row->expires_on;
                }
                $covered += (int) $row->qty_added;
                if ($covered >= $qty) {
                    break;
                }
            }
        }

        return $out;
    }

    /** Fill a W pallet (new, or an existing sealed/open one) and place it. */
    public function fill(Request $request)
    {
        $data = $request->validate([
            'pallet_id' => 'nullable|exists:pallets,id',
            'location_id' => 'nullable|exists:locations,id',
            'notes' => 'nullable|string|max:500',
            // Earliest expiration of the contents; present (even null) sets it, absent leaves an existing pallet's alone.
            'expires_on' => 'nullable|date',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|exists:items,id',
            'lines.*.qty' => 'required|integer|min:1',
            // This line's own earliest expiration, for its inventory label (null = none known).
            'lines.*.expires_on' => 'nullable|date',
        ]);

        // Several lines for the same item are one demand on the pool.
        $wanted = collect($data['lines'])->groupBy('item_id')->map(fn ($rows) => (int) $rows->sum('qty'));

        return DB::transaction(function () use ($data, $wanted) {
            // Serialise concurrent fills: two drivers must not both claim the last unassigned cases.
            Item::whereIn('id', $wanted->keys())->orderBy('id')->lockForUpdate()->get();

            foreach ($wanted as $itemId => $qty) {
                $free = PalletStock::unassigned((int) $itemId);
                if ($qty > $free) {
                    $name = Item::find($itemId)?->description ?? "item #{$itemId}";

                    return response()->json(['errors' => ['lines' => ["Only {$free} of \"{$name}\" are not already on a pallet — can't put {$qty} on this one."]]], 422);
                }
            }

            if (! empty($data['pallet_id'])) {
                $pallet = Pallet::findOrFail($data['pallet_id']);
                if ($error = $this->notFillable($pallet)) {
                    return response()->json(['errors' => ['pallet_id' => [$error]]], 422);
                }
            } else {
                $pallet = Pallet::create([
                    'kind' => PalletKind::WAREHOUSE,
                    'status' => 'sealed',
                    'container_type' => 'pallet',
                    'location_id' => $data['location_id'] ?? null,
                    'datepacked' => now()->toDateString(),
                    'earliest_expiry' => $data['expires_on'] ?? null,
                ]);
                $pallet->statuses()->create(['location_id' => $pallet->location_id, 'status' => 'sealed', 'notes' => 'Filled and sealed']);
            }

            if (! empty($data['pallet_id']) && array_key_exists('expires_on', $data)) {
                $pallet->update(['earliest_expiry' => $data['expires_on']]);
            }

            // One assignment row per line is one inventory label (see InventoryLabelCode); the ids go back so the
            // screen can offer to print them.
            $assignmentIds = [];
            foreach ($data['lines'] as $line) {
                $assignmentIds[] = PalletStockAssignment::create([
                    'pallet_id' => $pallet->id,
                    'item_id' => $line['item_id'],
                    'qty' => $line['qty'],
                    'expires_on' => $line['expires_on'] ?? null,
                    'person_id_user' => Auth::id(),
                    'notes' => $data['notes'] ?? null,
                ])->id;
            }

            if (! empty($data['location_id']) && (int) $data['location_id'] !== (int) $pallet->location_id) {
                $pallet->transitionTo($pallet->status, (int) $data['location_id'], $data['notes'] ?? 'Placed after filling');
            }

            return response()->json([
                'message' => "Pallet {$pallet->tag} filled.",
                'record' => $this->payload($pallet->fresh('location:id,code')),
                'assignment_ids' => $assignmentIds,
            ], 201);
        });
    }

    /** Unseal a sealed Warehouse pallet (it can then be picked from, added to or adjusted). */
    public function open($id)
    {
        $pallet = Pallet::findOrFail($id);
        if ($error = $this->notWarehouseOr($pallet, 'sealed', 'unsealed')) {
            return $error;
        }
        $pallet->transitionTo('open', null, 'Unsealed');

        return response()->json(['record' => $this->payload($pallet->fresh('location:id,code'))]);
    }

    /** Re-seal an open Warehouse pallet. */
    public function seal($id)
    {
        $pallet = Pallet::findOrFail($id);
        if ($error = $this->notWarehouseOr($pallet, 'open', 'sealed')) {
            return $error;
        }
        $pallet->transitionTo('sealed', null, 'Re-sealed');

        return response()->json(['record' => $this->payload($pallet->fresh('location:id,code'))]);
    }

    private function notWarehouseOr(Pallet $pallet, string $needs, string $verb)
    {
        if ($pallet->kind !== PalletKind::WAREHOUSE) {
            return response()->json(['message' => 'Only Warehouse pallets are sealed and unsealed.'], 422);
        }
        if ($pallet->status !== $needs) {
            return response()->json(['message' => "{$pallet->tag} is {$pallet->status}; only a {$needs} pallet can be {$verb}."], 422);
        }

        return null;
    }

    /**
     * A manual inventory adjustment of an unsealed, already-placed Warehouse pallet: signed quantity changes per
     * item, with a required reason. Unlike filling (which moves stock out of the sorting area), this changes how
     * much stock exists, so it is logged as its own 'adjustment' transaction whose ledger rows name the pallet. Never
     * mixed with a fill: this endpoint only changes quantities already on the pallet.
     */
    public function adjustInventory(Request $request, $id)
    {
        $pallet = Pallet::findOrFail($id);
        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(self::ADJUSTMENT_REASONS)),
            'notes' => 'nullable|string|max:500|required_if:reason,other',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|exists:items,id',
            'lines.*.qty' => 'required|integer|not_in:0',
        ]);

        if ($pallet->kind !== PalletKind::WAREHOUSE) {
            return response()->json(['message' => 'Only Warehouse pallets can be adjusted.'], 422);
        }
        if ($pallet->status !== 'open') {
            return response()->json(['message' => "{$pallet->tag} is {$pallet->status} — it must be unsealed to adjust it."], 422);
        }
        if (! $pallet->location_id) {
            return response()->json(['message' => "{$pallet->tag} has no location yet — place it before adjusting it."], 422);
        }

        $changes = collect($data['lines'])->groupBy('item_id')->map(fn ($rows) => (int) $rows->sum('qty'))->filter();
        if ($changes->isEmpty()) {
            return response()->json(['errors' => ['lines' => ['Nothing to adjust — every change nets to zero.']]], 422);
        }

        return DB::transaction(function () use ($pallet, $data, $changes) {
            Item::whereIn('id', $changes->keys())->orderBy('id')->lockForUpdate()->get();

            foreach ($changes as $itemId => $qty) {
                $have = (int) PalletStock::quantities([$pallet->id], [(int) $itemId])->sum('qty');
                if ($qty < 0 && $have + $qty < 0) {
                    $name = Item::find($itemId)?->description ?? "item #{$itemId}";

                    return response()->json(['errors' => ['lines' => ["{$pallet->tag} only has {$have} of \"{$name}\" — can't take off ".abs($qty).'.']]], 422);
                }
            }

            $adjustment = Transaction::create([
                'type' => 'adjustment',
                'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
                'adjustment_reason' => $data['reason'],
                'comments' => $data['notes'] ?? null,
                'person_id_user' => Auth::id(),
                'order_date' => now()->toDateString(),
            ]);

            foreach ($changes as $itemId => $qty) {
                ItemLedger::create([
                    'orderdonation_id' => $adjustment->id,
                    'item_id' => $itemId,
                    'qty_added' => max($qty, 0),
                    'qty_subtracted' => max(-$qty, 0),
                    'disposition' => 'usable',
                    'stock_pallet_id' => $pallet->id,
                    'person_id_user' => Auth::id(),
                ]);
            }

            $pallet->transitionTo($pallet->status, null, 'Inventory adjustment: '.self::ADJUSTMENT_REASONS[$data['reason']].(! empty($data['notes']) ? ' — '.$data['notes'] : ''));
            if (PalletStock::palletTotal($pallet->id) <= 0) {
                $pallet->transitionTo('empty', null, 'Adjusted to empty');
            }

            return response()->json(['message' => "{$pallet->tag} adjusted.", 'record' => $this->payload($pallet->fresh('location:id,code'))], 201);
        });
    }

    /** Correct what the database believes is on a pallet (signed change; a pallet can't go below zero of an item). */
    public function adjust(Request $request, $id)
    {
        $pallet = Pallet::findOrFail($id);
        $data = $request->validate([
            'item_id' => 'required|exists:items,id',
            'qty' => 'required|integer|not_in:0',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($pallet, $data) {
            Item::whereKey($data['item_id'])->lockForUpdate()->get();
            $have = (int) PalletStock::quantities([$pallet->id], [(int) $data['item_id']])->sum('qty');

            if ($data['qty'] < 0 && $have + $data['qty'] < 0) {
                return response()->json(['errors' => ['qty' => ["That pallet only has {$have} of this item."]]], 422);
            }
            if ($data['qty'] > 0 && $data['qty'] > PalletStock::unassigned((int) $data['item_id'])) {
                return response()->json(['errors' => ['qty' => ['Not enough unassigned stock of this item to add that many.']]], 422);
            }

            PalletStockAssignment::create($data + ['pallet_id' => $pallet->id, 'person_id_user' => Auth::id()]);

            return response()->json(['record' => $this->payload($pallet->fresh('location:id,code'))]);
        });
    }

    private function notFillable(Pallet $pallet): ?string
    {
        if ($pallet->kind !== PalletKind::WAREHOUSE) {
            return 'That is a '.(PalletKind::LABELS[$pallet->kind] ?? $pallet->kind).' pallet, not a Warehouse pallet.';
        }
        if ($pallet->status === 'sealed') {
            return "{$pallet->tag} is sealed — unseal it before adding to it.";
        }
        if ($pallet->status !== 'open') {
            return "That pallet is {$pallet->status} and can't take more stock.";
        }

        return null;
    }

    /** Pallet plus its contents (items with quantity above zero). */
    public static function payload(Pallet $pallet): array
    {
        $rows = PalletStock::quantities([$pallet->id])->filter(fn ($r) => $r['qty'] !== 0);
        $items = Item::with('itemtype:id,name')->whereIn('id', $rows->pluck('item_id'))->get()->keyBy('id');

        return [
            'id' => $pallet->id,
            'tag' => $pallet->tag,
            'kind' => $pallet->kind,
            'status' => $pallet->status,
            'location_id' => $pallet->location_id,
            'location' => $pallet->location,
            'expires_on' => $pallet->earliest_expiry,
            'contents' => $rows->map(fn ($r) => [
                'item_id' => $r['item_id'],
                'name' => $items[$r['item_id']]->description ?? $items[$r['item_id']]->itemtype?->name ?? '#'.$r['item_id'],
                'qty' => $r['qty'],
            ])->values(),
            // What was put on this pallet, one row per inventory label (newest first), so a label can be reprinted.
            // Quantities are as added; later picks and corrections aren't reflected (see InventoryLabelController).
            'labels' => PalletStockAssignment::with('item.itemtype')->where('pallet_id', $pallet->id)->where('qty', '>', 0)
                ->orderByDesc('id')->limit(50)->get()->map(fn (PalletStockAssignment $a) => [
                    'id' => $a->id,
                    'item_id' => $a->item_id,
                    'name' => $a->item?->description ?? $a->item?->itemtype?->name ?? '#'.$a->item_id,
                    'qty' => $a->qty,
                    'expires_on' => $a->expires_on?->toDateString(),
                ])->values(),
        ];
    }
}
