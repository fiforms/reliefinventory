<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use App\Models\ItemType;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Realistic-looking warehouse activity for demo/dashboard-building purposes:
 * donations at every stage of Receiving -> Sorting -> Complete, a spread of
 * usable stock on hand across many item types, and orders across the full
 * New Order -> Filling -> Filled -> Shipped lifecycle. Goes through the same
 * model methods (Pallet::transitionTo(), Transaction::create(), etc.) real
 * usage does, rather than raw inserts, so status rollups/history are
 * consistent with what the app actually produces.
 *
 * Intended for the demo instance only — never run against wa26 or any
 * instance carrying real operational data. Not idempotent by quantity (running
 * twice adds a second batch of pallets/orders) but safe to repeat: people and
 * locations are matched by unique fields (email/code) so those don't duplicate.
 */
class WarehouseActivitySeeder extends Seeder
{
    private array $stockOnHand = []; // item_id => remaining usable qty, tracked so order fills stay plausible

    public function run(): void
    {
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Default Warehouse', 'pallets_enabled' => true]);
        $locations = $this->ensureLocations($warehouse);
        $donors = $this->ensureDonors();
        $partners = $this->ensurePartners();
        $items = $this->pickItemsForActivity();

        $this->seedReceivedDonation($donors[0]);
        $this->seedSortingDonation($donors[1], $items);
        $this->seedCompleteDonation($donors[2], $items, $locations);
        $this->seedWarehouseStock($items, $locations);
        $this->seedNonWarehousePallets($locations, $partners);

        $this->seedOrders($partners, $items);
    }

    // ---------- people & locations ----------

    private function ensureDonors(): array
    {
        $rows = [
            ['first_name' => 'American', 'last_name' => 'Red Cross', 'organization' => 'American Red Cross', 'email' => 'donations@example-redcross.org', 'city' => 'Seattle', 'state' => 'WA'],
            ['first_name' => 'Grace', 'last_name' => 'Community', 'organization' => 'Grace Community Church', 'email' => 'outreach@example-gracecc.org', 'city' => 'Tacoma', 'state' => 'WA'],
            ['first_name' => 'Costco', 'last_name' => 'Wholesale', 'organization' => 'Costco Wholesale #442', 'email' => 'donations@example-costco.com', 'city' => 'Olympia', 'state' => 'WA'],
        ];

        return collect($rows)->map(fn ($row) => Person::firstOrCreate(['email' => $row['email']], $row))->all();
    }

    private function ensurePartners(): array
    {
        $rows = [
            ['first_name' => 'First', 'last_name' => 'Baptist', 'organization' => 'First Baptist Church POD', 'email' => 'pod@example-fbc.org', 'city' => 'Lacey', 'state' => 'WA'],
            ['first_name' => 'Riverside', 'last_name' => 'Elementary', 'organization' => 'Riverside Elementary Shelter', 'email' => 'shelter@example-riverside.k12.wa.us', 'city' => 'Puyallup', 'state' => 'WA'],
            ['first_name' => 'Community', 'last_name' => 'Response', 'organization' => 'CERT Team 4', 'email' => 'team4@example-cert.org', 'city' => 'Yelm', 'state' => 'WA'],
            ['first_name' => 'Maria', 'last_name' => 'Santos', 'organization' => null, 'email' => 'msantos@example.com', 'city' => 'Olympia', 'state' => 'WA'],
        ];

        return collect($rows)->map(fn ($row) => Person::firstOrCreate(['email' => $row['email']], $row))->all();
    }

    private function ensureLocations(Warehouse $warehouse): array
    {
        $codes = ['A-01', 'A-02', 'A-03', 'B-01', 'B-02', 'DOCK-1'];

        // warehouse_id isn't mass-assignable on Location (not in $fillable),
        // so set it directly rather than via firstOrCreate's attributes.
        return collect($codes)->map(function ($code) use ($warehouse) {
            $location = Location::where('code', $code)->first();
            if (! $location) {
                $location = new Location(['code' => $code, 'status' => 'active']);
                $location->warehouse_id = $warehouse->id;
                $location->save();
            }

            return $location;
        })->all();
    }

    /**
     * A spread of real, orderable item types across several categories —
     * enough variety for a dashboard to look like an actual warehouse
     * rather than three items repeated everywhere.
     */
    private function pickItemsForActivity()
    {
        return ItemType::with('items')
            ->where('status', 'orderable')
            ->whereHas('items')
            ->inRandomOrder()
            ->limit(30)
            ->get()
            ->map(fn (ItemType $itemType) => (object) [
                'itemtype' => $itemType,
                'item' => $itemType->items->first(),
            ])
            ->values();
    }

    // ---------- donations at each stage ----------

    private function seedReceivedDonation(Person $donor): void
    {
        $donation = Transaction::create([
            'type' => 'donation',
            'category' => 'donation',
            'person_id' => $donor->id,
            'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
            'order_date' => Carbon::now()->subHours(6)->toDateString(),
            'manifest' => 'Mixed pallets, dock drop-off',
            'container_count' => 3,
        ]);
        $donation->forceFill(['created_at' => Carbon::now()->subHours(6)])->save();

        for ($i = 0; $i < 3; $i++) {
            $pallet = Pallet::create([
                'kind' => 'R',
                'status' => 'received',
                'container_type' => 'pallet',
                'donor_person_id' => $donor->id,
                'orderdonation_id' => $donation->id,
                'content_description' => 'Mixed pallet, not yet sorted',
                'datepacked' => Carbon::now()->subHours(6)->toDateString(),
            ]);
            $pallet->statuses()->create(['status' => 'received']);
        }
    }

    private function seedSortingDonation(Person $donor, $items): void
    {
        $donation = Transaction::create([
            'type' => 'donation',
            'category' => 'donation',
            'person_id' => $donor->id,
            'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
            'order_date' => Carbon::now()->subDays(1)->toDateString(),
            'manifest' => 'Church donation drive, 2 pallets',
            'container_count' => 2,
        ]);
        $donation->forceFill(['created_at' => Carbon::now()->subDays(1)])->save();

        // Both pallets must exist before either transitions — the
        // "every pallet empty -> Complete" rollup checks all of a
        // donation's pallets at the moment one of them empties, so
        // emptying the first before the second exists would prematurely
        // (and permanently, since the rollup never reverses) complete it.
        $makePallet = fn () => tap(Pallet::create([
            'kind' => 'R', 'status' => 'received', 'container_type' => 'pallet',
            'donor_person_id' => $donor->id, 'orderdonation_id' => $donation->id,
            'datepacked' => Carbon::now()->subDays(1)->toDateString(),
        ]), fn (Pallet $p) => $p->statuses()->create(['status' => 'received']));

        $emptied = $makePallet();
        $inProgress = $makePallet();

        // One pallet already fully sorted (empty), one still being worked —
        // this mix is exactly what puts the donation in "Sorting" status.
        $emptied->transitionTo('sorting'); // first pallet to leave "received" starts Sorting
        foreach ($items->slice(0, 3) as $entry) {
            $donation->itemLedgers()->create([
                'item_id' => $entry->item->id, 'pallet_id' => $emptied->id,
                'qty_added' => rand(10, 40), 'disposition' => 'usable',
            ]);
        }
        $emptied->transitionTo('empty'); // inProgress is still "received", so this does NOT complete the donation

        $inProgress->transitionTo('sorting');
        foreach ($items->slice(3, 2) as $entry) {
            $donation->itemLedgers()->create([
                'item_id' => $entry->item->id, 'pallet_id' => $inProgress->id,
                'qty_added' => rand(5, 20), 'disposition' => 'usable',
            ]);
        }
        // left in "sorting" — the sorter hasn't hit Pallet Empty yet
    }

    private function seedCompleteDonation(Person $donor, $items, array $locations): void
    {
        $donation = Transaction::create([
            'type' => 'donation',
            'category' => 'donation',
            'person_id' => $donor->id,
            'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
            'order_date' => Carbon::now()->subDays(4)->toDateString(),
            'manifest' => 'Wholesale club pallet donation',
            'container_count' => 2,
        ]);
        $donation->forceFill(['created_at' => Carbon::now()->subDays(4)])->save();

        // Both pallets exist before either empties — see the comment in
        // seedSortingDonation() on why order matters for the rollup.
        $pallets = collect([0, 1])->map(fn ($i) => tap(Pallet::create([
            'kind' => 'R', 'status' => 'received', 'container_type' => 'pallet',
            'donor_person_id' => $donor->id, 'orderdonation_id' => $donation->id,
            'location_id' => $locations[5]->id, // DOCK-1
            'datepacked' => Carbon::now()->subDays(4)->toDateString(),
        ]), fn (Pallet $p) => $p->statuses()->create(['status' => 'received'])));

        foreach ($pallets as $i => $pallet) {
            $pallet->transitionTo('sorting');

            foreach ($items->slice(6 + $i * 3, 3) as $entry) {
                // Mostly usable, with a realistic sprinkle of loss —
                // matches donor-quality reporting's expected shape.
                $donation->itemLedgers()->create([
                    'item_id' => $entry->item->id, 'pallet_id' => $pallet->id,
                    'qty_added' => rand(20, 60), 'disposition' => 'usable',
                ]);
                $this->stockOnHand[$entry->item->id] = ($this->stockOnHand[$entry->item->id] ?? 0) + rand(20, 60);
                if (rand(1, 4) === 1) {
                    $donation->itemLedgers()->create([
                        'item_id' => $entry->item->id, 'pallet_id' => $pallet->id,
                        'qty_added' => rand(1, 5), 'disposition' => collect(['outdated', 'trashed'])->random(),
                    ]);
                }
            }

            $pallet->transitionTo('empty'); // last one flips the donation to Complete
        }
    }

    // ---------- broader warehouse stock ----------

    /**
     * Warehouse (W) pallets carrying stock unrelated to the three donation
     * narratives above — gives the on-hand report real breadth instead of
     * only the items three specific donations happened to touch.
     */
    private function seedWarehouseStock($items, array $locations): void
    {
        $donation = Transaction::create([
            'type' => 'donation',
            'category' => 'donation',
            'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
            'order_date' => Carbon::now()->subDays(7)->toDateString(),
            'manifest' => 'Backfilled warehouse stock (seed data)',
        ]);
        $donation->forceFill(['created_at' => Carbon::now()->subDays(7)])->save();

        $statuses = ['sealed', 'sealed', 'open', 'open', 'open', 'empty'];
        foreach ($items->slice(12, 18) as $i => $entry) {
            $status = $statuses[$i % count($statuses)];
            $pallet = Pallet::create([
                'kind' => 'W', 'status' => 'sealed', 'container_type' => 'pallet',
                'location_id' => $locations[$i % 5]->id,
                'content_item_id' => $entry->item->id,
                'datepacked' => Carbon::now()->subDays(rand(5, 30))->toDateString(),
            ]);
            $pallet->statuses()->create(['status' => 'sealed']);
            if ($status !== 'sealed') {
                $pallet->transitionTo($status);
            }

            if ($status === 'empty') {
                continue; // depleted pallet: no stock contribution
            }

            $qty = rand(15, 120);
            $donation->itemLedgers()->create([
                'item_id' => $entry->item->id, 'pallet_id' => $pallet->id,
                'qty_added' => $qty, 'disposition' => 'usable',
            ]);
            $this->stockOnHand[$entry->item->id] = ($this->stockOnHand[$entry->item->id] ?? 0) + $qty;
        }
    }

    /**
     * One pallet each of Shipping/Hold/Quarantine so the dashboard has all
     * five kinds represented, not just Receiving/Warehouse.
     */
    private function seedNonWarehousePallets(array $locations, array $partners): void
    {
        $shipping = Pallet::create([
            'kind' => 'S', 'status' => 'building', 'container_type' => 'pallet',
            'destination_person_id' => $partners[0]->id,
            'location_id' => $locations[5]->id,
            'datepacked' => Carbon::now()->subHours(2)->toDateString(),
        ]);
        $shipping->statuses()->create(['status' => 'building']);

        $hold = Pallet::create([
            'kind' => 'H', 'status' => 'filling', 'container_type' => 'gaylord',
            'location_id' => $locations[4]->id,
            'content_description' => 'Damaged case, awaiting supervisor review',
            'condition' => 'pending',
            'datepacked' => Carbon::now()->subDays(2)->toDateString(),
        ]);
        $hold->statuses()->create(['status' => 'filling']);

        $quarantine = Pallet::create([
            'kind' => 'Q', 'status' => 'held', 'container_type' => 'pallet',
            'location_id' => $locations[3]->id,
            'content_description' => 'Recalled infant formula lot — hold for disposition',
            'datepacked' => Carbon::now()->subDays(3)->toDateString(),
        ]);
        $quarantine->statuses()->create(['status' => 'held']);
    }

    // ---------- orders across the full lifecycle ----------

    private function seedOrders(array $partners, $items): void
    {
        $newOrderLines = [
            [$items[0], 20], [$items[1], 15], [$items[2], 30],
        ];
        $this->makeOrder($partners[0], Transaction::STATUS_NEW_ORDER, $newOrderLines, fillFraction: 0.0, daysAgo: 0);

        $this->makeOrder($partners[1], Transaction::STATUS_NEW_ORDER, [
            [$items[3], 10], [$items[4], 25],
        ], fillFraction: 0.0, daysAgo: 0);

        $this->makeOrder($partners[2], Transaction::STATUS_NEW_ORDER, [
            [$items[5], 40],
        ], fillFraction: 0.0, daysAgo: 1);

        $this->makeOrder($partners[3], Transaction::STATUS_FILLING, [
            [$items[12], 20], [$items[13], 10], [$items[14], 15],
        ], fillFraction: 0.4, daysAgo: 1);

        $this->makeOrder($partners[0], Transaction::STATUS_FILLING, [
            [$items[15], 12], [$items[16], 8],
        ], fillFraction: 0.5, daysAgo: 2);

        $this->makeOrder($partners[1], Transaction::STATUS_FILLED, [
            [$items[17], 18], [$items[18], 6],
        ], fillFraction: 1.0, daysAgo: 3);

        $this->makeOrder($partners[2], Transaction::STATUS_FILLED, [
            [$items[19], 25],
        ], fillFraction: 1.0, daysAgo: 4);

        $this->makeOrder($partners[3], Transaction::STATUS_SHIPPED, [
            [$items[20], 15], [$items[21], 10],
        ], fillFraction: 1.0, daysAgo: 6);
    }

    /**
     * @param  array<array{0: object, 1: int}>  $lines  [itemEntry, qty_requested] pairs
     */
    private function makeOrder(Person $partner, string $statusName, array $lines, float $fillFraction, int $daysAgo): void
    {
        $order = Transaction::create([
            'type' => 'order',
            'person_id' => $partner->id,
            'status_id' => Transaction::statusId($statusName),
            'order_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
        ]);
        $order->forceFill(['created_at' => Carbon::now()->subDays($daysAgo)])->save();

        foreach ($lines as [$entry, $qtyRequested]) {
            $order->orderLines()->create([
                'itemtype_id' => $entry->itemtype->id,
                'qty_requested' => $qtyRequested,
            ]);

            if ($fillFraction <= 0) {
                continue;
            }

            $available = $this->stockOnHand[$entry->item->id] ?? 0;
            $toFill = (int) round(min($qtyRequested, $available) * $fillFraction);
            if ($toFill <= 0) {
                continue;
            }

            $order->itemLedgers()->create([
                'item_id' => $entry->item->id,
                'qty_subtracted' => $toFill,
            ]);
            $this->stockOnHand[$entry->item->id] = $available - $toFill;
        }
    }
}
