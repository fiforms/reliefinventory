<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Item;
use App\Models\Pallet;

/**
 * Where a picker should take an item type from, so as few pallets as possible leave the warehouse broken.
 *
 * For a need of N:
 *  1. the sorting-area pool (loose, not yet on a pallet);
 *  2. whole SEALED pallets, expiring ones first (earliest date first), then oldest first, while the remaining need is at least that pallet's quantity — a full
 *     pallet ships intact and needs no opening;
 *  3. the rest from OPEN pallets, expiring first then oldest (they're already broken, so finishing one costs nothing);
 *  4. only if that still isn't enough, open the next remaining SEALED pallet in the same order for the last of it.
 * E.g. 750 water with ten sealed 100s and one open 75: seven sealed pallets, then 50 off the open one — a single broken
 * pallet, not 75 off the open one plus seven more sealed pallets opened.
 *
 * Steps come back in walking order (the admin's pick path, `locations.PullSequence`; the sorting area first, unknown
 * locations last). Advisory only: nothing is reserved, so it is recomputed from live stock every time.
 */
class PickPlanner
{
    /**
     * @return array{steps: array<int, array<string, mixed>>, short: int, first_sequence: ?int}
     */
    public static function plan(int $itemtypeId, int $need): array
    {
        return self::planFor(self::holdings($itemtypeId), $need);
    }

    /** Stock holdings of an item type: pool per item plus every pallet/item pair with quantity above zero. */
    public static function holdings(int $itemtypeId): array
    {
        $items = Item::where('itemtype_id', $itemtypeId)->get(['id', 'description'])->keyBy('id');
        $pallets = [];
        $rows = PalletStock::quantities(null, $items->keys()->all())->filter(fn ($r) => $r['qty'] > 0);
        if ($rows->isNotEmpty()) {
            $pallets = Pallet::with('location:id,code,PullSequence')->whereIn('id', $rows->pluck('pallet_id'))->get()->keyBy('id')->all();
        }

        $pool = [];
        foreach ($items as $id => $item) {
            $free = PalletStock::unassigned($id);
            if ($free > 0) {
                $pool[] = ['item_id' => $id, 'item' => $item->description, 'qty' => $free];
            }
        }

        $held = [];
        foreach ($rows as $r) {
            $pallet = $pallets[$r['pallet_id']] ?? null;
            if (! $pallet || ! in_array($pallet->status, ['sealed', 'open'], true)) {
                continue;
            }
            $held[] = [
                'pallet_id' => $pallet->id, 'tag' => $pallet->tag, 'status' => $pallet->status,
                'location' => $pallet->location?->code, 'sequence' => $pallet->location?->PullSequence,
                'expires_on' => $pallet->earliest_expiry,
                'item_id' => $r['item_id'], 'item' => $items[$r['item_id']]->description, 'qty' => $r['qty'],
            ];
        }

        return ['pool' => $pool, 'pallets' => $held];
    }

    /** Pure planning over given holdings — split out so the allocation rule is testable without a database. */
    public static function planFor(array $holdings, int $need): array
    {
        $steps = [];
        $remaining = max(0, $need);

        foreach ($holdings['pool'] as $pool) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $pool['qty']);
            $steps[] = ['kind' => 'pool', 'item_id' => $pool['item_id'], 'item' => $pool['item'], 'qty' => $take,
                'location' => null, 'sequence' => null, 'tag' => null, 'pallet_id' => null, 'status' => null, 'needs_open' => false];
            $remaining -= $take;
        }

        // Pallets with an expiration date go first, earliest date first; undated pallets follow, oldest pallet first.
        $byAge = fn (string $status) => collect($holdings['pallets'])->where('status', $status)
            ->sortBy(fn ($h) => [($h['expires_on'] ?? null) === null ? 1 : 0, (string) ($h['expires_on'] ?? ''), $h['pallet_id']])->values()->all();
        $sealed = $byAge('sealed');
        $open = $byAge('open');
        $taken = [];

        $step = fn (array $h, int $qty, bool $needsOpen) => array_merge($h, ['kind' => 'pallet', 'qty' => $qty, 'needs_open' => $needsOpen, 'whole' => $qty === $h['qty']]);

        // 2. whole sealed pallets that fit. One oldest-first pass suffices: the remaining need only shrinks, so a pallet
        // too big now never fits later.
        foreach ($sealed as $i => $h) {
            if ($remaining > 0 && $h['qty'] <= $remaining) {
                $steps[] = $step($h, $h['qty'], true);
                $remaining -= $h['qty'];
                $taken[$i] = true;
            }
        }
        // 3. open pallets, oldest first.
        foreach ($open as $h) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $h['qty']);
            $steps[] = $step($h, $take, false);
            $remaining -= $take;
        }
        // 4. still short: break the oldest remaining sealed pallet(s).
        foreach ($sealed as $i => $h) {
            if ($remaining <= 0) {
                break;
            }
            if (isset($taken[$i])) {
                continue;
            }
            $take = min($remaining, $h['qty']);
            $steps[] = $step($h, $take, true);
            $remaining -= $take;
        }

        // Walking order: sorting area first, then the pick path; locations with no sequence at the end.
        usort($steps, fn ($a, $b) => [$a['kind'] === 'pool' ? 0 : 1, $a['sequence'] ?? PHP_INT_MAX, $a['pallet_id'] ?? 0]
            <=> [$b['kind'] === 'pool' ? 0 : 1, $b['sequence'] ?? PHP_INT_MAX, $b['pallet_id'] ?? 0]);

        $sequences = array_filter(array_column($steps, 'sequence'), fn ($s) => $s !== null);

        return [
            'steps' => $steps,
            'short' => $remaining,
            // Where this line first sends the picker, used to order lines along the path (null = unknown/last).
            'first_sequence' => ($steps && $steps[0]['kind'] === 'pool') ? -1 : ($sequences ? min($sequences) : null),
        ];
    }

    /**
     * Plan several lines in turn against the same stock: each plan reserves what it takes, so two lines (or two orders
     * on one batch of pick sheets) are never sent to the same cases. [lineKey => ['itemtype_id' => , 'need' => ]].
     *
     * @return array<int|string, array{steps: array, short: int, first_sequence: ?int}>
     */
    public static function planMany(array $lines): array
    {
        $holdings = [];
        $plans = [];
        foreach ($lines as $key => ['itemtype_id' => $itemtypeId, 'need' => $need]) {
            $holdings[$itemtypeId] ??= self::holdings($itemtypeId);
            $plans[$key] = self::planFor($holdings[$itemtypeId], $need);
            self::consume($holdings[$itemtypeId], $plans[$key]);
        }

        return $plans;
    }

    /** Deduct a plan's steps from the holdings it was made from. */
    public static function consume(array &$holdings, array $plan): void
    {
        foreach ($plan['steps'] as $step) {
            if ($step['kind'] === 'pool') {
                foreach ($holdings['pool'] as &$pool) {
                    if ($pool['item_id'] === $step['item_id']) {
                        $pool['qty'] -= $step['qty'];
                    }
                }
                unset($pool);
            } else {
                foreach ($holdings['pallets'] as &$held) {
                    if ($held['pallet_id'] === $step['pallet_id'] && $held['item_id'] === $step['item_id']) {
                        $held['qty'] -= $step['qty'];
                    }
                }
                unset($held);
            }
        }
        $holdings['pool'] = array_values(array_filter($holdings['pool'], fn ($p) => $p['qty'] > 0));
        $holdings['pallets'] = array_values(array_filter($holdings['pallets'], fn ($h) => $h['qty'] > 0));
    }
}
