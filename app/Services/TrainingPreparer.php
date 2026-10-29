<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\DonationOffer;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\PalletStockAssignment;
use App\Models\PeopleRoles;
use App\Models\Person;
use App\Models\Role;
use App\Models\TrainingProduct;
use App\Models\TrainingSetting;
use App\Models\Transaction;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Prepare Training: loads starter work so the first team at each station has something to do (config/training.php
 * `stage`). Receiving and Sorting use the trainer's own goods, put out by hand and never scanned: Receiving only gets
 * offers to match deliveries to, and Sorting sorts what Receiving brings in; Stations 3–5 work on goods the system must already know are there, so those are the
 * trainer's real products from the scanned list (App\Models\TrainingProduct), recorded as stock:
 *
 *   1 Receiving — pending donation offers for the goods put out to receive (no stock: they come in for real)
 *   2 Sorting   — nothing: what the receiving teams bring in (and label) is what gets sorted
 *   3 Inventory — scanned goods on the sorting-area table (sorted, not on a pallet)
 *   4 Picking   — sealed W pallets at table spots A-1 ... C-4 holding scanned goods (and any scanned beyond what
 *                 Stations 3–5 need), and Ready to Fill orders that can be picked from them
 *   5 Shipping  — Filled orders of scanned goods, already picked, staged at the door
 *   6 Ordering / Delivery — a "Team N Driver" per team (phone + PIN for the Driver Portal) and the training partners.
 *                 Nothing to stage: each team delivers the load it shipped itself at Station 5.
 *
 * After its first stop every team works on goods it handled itself one station earlier, which is why so little is
 * staged. The returned summary (also saved on training_settings.prepared_summary) is the setup packet: which scanned
 * goods go at which spot or in which load. Every scanned unit appears there exactly once.
 *
 * Writes go through the real models in one transaction, recorded as the person who pressed the button, tagged
 * source_system = 'training', with ActivityRecorder paused (starter data isn't anyone's training work). Running it
 * again adds another set. Refused outside a training warehouse, so it can't be pressed on a real one by accident.
 */
class TrainingPreparer
{
    public const SOURCE = 'training';

    /** Locations an earlier version of Prepare Training made; retired on every run (archived if anything used them). */
    private const OLD_LOCATION_PATTERN = '/^TR-(DOCK|SORT|A-\d{2})$/';

    public const SORTING_LOCATION = 'Sorting Area';

    private int $actorId;

    private string $batch;

    /** @var array<int, array{item_id: int, itemtype_id: int, number: string, type: string, name: string}> */
    private array $catalog = [];

    /** @var int[] item ids, one per unit, in the order they are handed out (see deal()) */
    private array $units = [];

    /** @var array<string, Location> */
    private array $locations = [];

    private array $summary = [];

    /**
     * How many scanned units Stations 3–5 are staged with; `target` is the least Prepare Training accepts (more is fine:
     * the extra goes on the Station 4 trays). Stations 1 and 2 use the trainer's own, unscanned goods.
     */
    public static function plan(): array
    {
        $s = config('training.stage');
        $stations = [
            3 => $s['sorting_area_units'],
            4 => $s['rack_pallets'] * $s['rack_pallet_size'],
            5 => $s['orders_to_ship'] * $s['ship_order_size'],
        ];

        return [
            'stations' => $stations,
            'target' => array_sum($stations),
            'min_products' => (int) config('training.min_products', 10),
        ];
    }

    /** The table spot codes, A-1 ... C-4. */
    public static function spotCodes(): array
    {
        $codes = [];
        foreach (config('training.tables', ['A', 'B', 'C']) as $table) {
            foreach (range(1, (int) config('training.spots_per_table', 4)) as $spot) {
                $codes[] = "$table-$spot";
            }
        }

        return $codes;
    }

    public function run(): array
    {
        if (! TrainingMode::active()) {
            throw new RuntimeException('Only a training warehouse can prepare training data.');
        }
        $this->actorId = (int) Auth::id();
        $this->batch = now()->format('YmdHis');
        $this->loadProducts();

        $summary = ActivityRecorder::paused(fn () => DB::transaction(function () {
            $this->retireOldLocations();
            $this->buildLocations();
            $donors = $this->buildDonors();
            $partners = $this->buildPartners();
            $s = config('training.stage');

            $this->summary['offers'] = $this->offers($donors);

            $stock = ['area' => $this->deal($s['sorting_area_units'])];
            foreach (range(1, $s['rack_pallets']) as $p) {
                $stock['racks'][] = $this->deal($s['rack_pallet_size']);
            }
            foreach (range(1, $s['orders_to_ship']) as $o) {
                $stock['ship'][] = $this->deal($s['ship_order_size']);
            }
            // Anything scanned beyond that goes on the trays, a unit at a time in turn, so there is more to pick.
            for ($p = 0; $this->units; $p = ($p + 1) % count($stock['racks'])) {
                foreach ($this->deal(1) as $itemId => $qty) {
                    $stock['racks'][$p][$itemId] = ($stock['racks'][$p][$itemId] ?? 0) + $qty;
                }
            }
            $this->stockShelves($donors[0], $stock);
            $this->summary['orders_to_fill'] = $this->ordersToFill($partners, $stock['racks']);
            $this->summary['orders_to_ship'] = $this->ordersToShip($partners, $stock['ship']);

            $this->summary['team_drivers'] = $this->buildDrivers();
            $this->summary['partners'] = array_map(fn (Person $p) => $p->organization, $partners);
            $this->summary['prepared_at'] = now()->toIso8601String();

            return $this->summary;
        }));

        TrainingSetting::current()->update([
            'prepared_at' => now(), 'prepared_by' => $this->actorId, 'prepared_summary' => $summary,
        ]);

        return $summary;
    }

    /**
     * The scanned goods, laid out as a single queue of units that deal() hands out from the front: products take
     * turns, two units at a time, so each tray and load gets a mix of products, and pairs give a quantity to pick.
     */
    private function loadProducts(): void
    {
        $products = TrainingProduct::with('item.itemtype')->where('qty', '>', 0)->get()
            ->filter(fn (TrainingProduct $p) => $p->item?->itemtype)
            ->sortBy([['qty', 'desc'], ['id', 'asc']])->values();
        $plan = self::plan();
        $total = (int) $products->sum('qty');
        if ($total < $plan['target']) {
            throw new RuntimeException(sprintf(
                'Scan at least %d more items first: %d scanned, %d are needed for Stations 3–5.', $plan['target'] - $total, $total, $plan['target']));
        }

        $left = [];
        foreach ($products as $p) {
            $type = $p->item->itemtype;
            $this->catalog[$p->item_id] = [
                'item_id' => $p->item_id, 'itemtype_id' => $type->id, 'number' => $type->display_number, 'type' => $type->name,
                'name' => trim((string) $p->item->description) ?: $type->name,
            ];
            $left[$p->item_id] = $p->qty;
        }
        while ($left) {
            foreach (array_keys($left) as $itemId) {
                $take = min(2, $left[$itemId]);
                array_push($this->units, ...array_fill(0, $take, $itemId));
                if (($left[$itemId] -= $take) === 0) {
                    unset($left[$itemId]);
                }
            }
        }

        $this->summary['products'] = ['units' => $total, 'distinct' => $products->count()];
        $this->summary['warnings'] = [];
        if ($products->count() < $plan['min_products']) {
            $this->summary['warnings'][] = "Only {$products->count()} different products were scanned (at least {$plan['min_products']} make better mixed trays and loads).";
        }
    }

    /** The next $n units off the queue, as item_id => qty (fewer if it runs out). */
    private function deal(int $n): array
    {
        $out = [];
        foreach (array_splice($this->units, 0, $n) as $itemId) {
            $out[$itemId] = ($out[$itemId] ?? 0) + 1;
        }

        return $out;
    }

    /** item_id => qty as printable lines: [{number, type, name, qty}]. */
    private function describe(array $items): array
    {
        $lines = [];
        foreach ($items as $itemId => $qty) {
            $c = $this->catalog[$itemId];
            $lines[] = ['item_id' => $itemId, 'number' => $c['number'], 'type' => $c['type'], 'name' => $c['name'], 'qty' => $qty];
        }

        return $lines;
    }

    /**
     * The locations an earlier Prepare Training made (TR-DOCK, TR-SORT, TR-A-01 ...): deleted if nothing ever used
     * them, otherwise archived — pallet history points at them, and archived locations drop out of scanning and lists.
     */
    private function retireOldLocations(): void
    {
        foreach (Location::where('code', 'like', 'TR-%')->get() as $location) {
            if (! preg_match(self::OLD_LOCATION_PATTERN, $location->code)) {
                continue;
            }
            $used = DB::table('pallets')->where('location_id', $location->id)->exists()
                || DB::table('palletstatus')->where('location_id', $location->id)->exists()
                || DB::table('containers')->where('location_id', $location->id)->exists();
            $used ? $location->update(['status' => 'archived']) : $location->delete();
        }
    }

    private function buildLocations(): void
    {
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Main Warehouse', 'pallets_enabled' => true]);
        $codes = [self::SORTING_LOCATION => []];
        foreach (self::spotCodes() as $i => $code) {
            [$table, $spot] = explode('-', $code);
            $codes[$code] = ['Aisle' => $table, 'Lane' => $spot, 'PullSequence' => 9000 + ($i + 1) * 10];
        }
        foreach ($codes as $code => $extra) {
            $location = Location::where('code', $code)->first();
            if (! $location) {
                $location = new Location(['code' => $code, 'status' => 'active'] + $extra);
                $location->warehouse_id = $warehouse->id;
                $location->save();
            } elseif ($location->status !== 'active') {
                $location->update(['status' => 'active']);
            }
            $this->locations[$code] = $location;
        }
        $this->summary['locations'] = self::spotCodes();
    }

    /** @return Person[] */
    private function buildDonors(): array
    {
        $role = Role::where('name', 'Donor')->value('id');
        $people = [];
        foreach (['Training Donor: Hope Church', 'Training Donor: Main Street Grocery', 'Training Donor: Valley Scouts'] as $i => $name) {
            $people[] = $this->organization($name, 'donor-'.($i + 1), $role);
        }

        return $people;
    }

    /** @return Person[] */
    private function buildPartners(): array
    {
        $role = Role::where('name', 'Partner')->value('id');
        $people = [];
        foreach (['Training Partner: Riverside Shelter', 'Training Partner: Eastside Food Pantry', 'Training Partner: County Distribution Point'] as $i => $name) {
            $person = $this->organization($name, 'partner-'.($i + 1), $role);
            if ($person->wasRecentlyCreated) {
                $person->transitionPartnerStatus(Person::PARTNER_STATUS_PENDING, $this->actorId, 'Training partner');
                $person->transitionPartnerStatus(Person::PARTNER_STATUS_APPROVED, $this->actorId, 'Training partner');
            }
            $people[] = $person;
        }

        return $people;
    }

    private function organization(string $name, string $ref, ?int $roleId): Person
    {
        $person = Person::firstOrCreate(
            ['source_system' => self::SOURCE, 'source_ref' => $ref],
            ['organization' => $name, 'is_organization' => true, 'first_name' => null, 'last_name' => null,
                'city' => 'Training', 'state' => 'NC', 'phone' => '555-0100'],
        );
        if ($roleId && ! PeopleRoles::where('person_id', $person->id)->where('role_id', $roleId)->exists()) {
            PeopleRoles::create(['person_id' => $person->id, 'role_id' => $roleId]);
        }

        return $person;
    }

    /** One driver per team, with a fixed, printed phone + PIN (the Driver Portal's login). */
    private function buildDrivers(): array
    {
        $drivers = [];
        foreach (TrainingMode::TEAMS as $team) {
            $phone = sprintf('555-010-%04d', $team);
            $pin = self::teamDriverPin($team);
            $driver = Driver::where('training_team', $team)->first()
                ?? Driver::create(['name' => "Team $team Driver", 'phone' => $phone, 'carrier' => 'Training']);
            $driver->forceFill(['training_team' => $team, 'phone' => $phone, 'pin_hash' => Hash::make($pin)])->save();
            $drivers[] = ['team' => $team, 'id' => $driver->id, 'name' => $driver->name, 'phone' => $phone, 'pin' => $pin];
        }

        return $drivers;
    }

    /** A PIN that passes SecurePin (no triple repeats, no 4-digit runs): team 1 => 10171, 2 => 20272, ... */
    public static function teamDriverPin(int $team): string
    {
        return "{$team}0{$team}7{$team}";
    }

    /** Station 1: pending donation offers for the trainer's own goods, put out to arrive. */
    private function offers(array $donors): array
    {
        $descriptions = ['Boxes of canned food', 'Mixed groceries', 'Snacks and drinks', 'Household goods', 'Small electronics and supplies'];
        $out = [];
        for ($i = 0; $i < config('training.stage.offers', 5); $i++) {
            $donor = $donors[$i % count($donors)];
            $offer = DonationOffer::create([
                'person_id' => $donor->id, 'description' => $descriptions[$i % count($descriptions)],
                'status' => DonationOffer::STATUS_OFFERED, 'entered_by_person_id' => $this->actorId,
            ]);
            $offer->statusLogs()->create(['from_status' => null, 'to_status' => DonationOffer::STATUS_OFFERED,
                'changed_by_person_id' => $this->actorId, 'contact_method' => 'phone', 'notes' => 'Training starter offer']);
            $offer->transitionTo(DonationOffer::STATUS_PENDING, $this->actorId,
                ['eta_start' => now()->toDateString(), 'eta_end' => now()->toDateString()], 'phone', 'Accepted; arriving today');
            $out[] = ['id' => $offer->id, 'donor' => $donor->organization, 'description' => $offer->description];
        }

        return $out;
    }

    /**
     * Stations 3–5's goods are already sorted: one sorted donation holding all of them, then the rack share put on
     * sealed W pallets, one per table first (A-1, B-1, C-1, A-2 ...). The sorting-area and shipping shares stay off
     * pallets (shipping's are picked from the sorting area in ordersToShip()).
     */
    private function stockShelves(Person $donor, array $stock): void
    {
        $all = [];
        foreach ([$stock['area'], ...$stock['racks'], ...$stock['ship']] as $items) {
            foreach ($items as $itemId => $qty) {
                $all[$itemId] = ($all[$itemId] ?? 0) + $qty;
            }
        }
        $sortArea = $this->locations[self::SORTING_LOCATION];
        $donation = Transaction::create([
            'type' => 'donation', 'category' => 'donation', 'person_id' => $donor->id, 'person_id_user' => $this->actorId,
            'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE), 'order_date' => now()->toDateString(),
            'container_count' => 1, 'container_types' => ['box'], 'container_type_counts' => ['box' => 1],
            'manifest' => 'Training starter stock', 'source_system' => self::SOURCE, 'source_ref' => $this->batch.'-stock',
        ]);
        $source = Pallet::create([
            'kind' => 'R', 'status' => 'received', 'container_type' => 'box', 'donor_person_id' => $donor->id,
            'orderdonation_id' => $donation->id, 'location_id' => $sortArea->id, 'datepacked' => now()->toDateString(),
        ]);
        $source->statuses()->create(['status' => 'received', 'location_id' => $sortArea->id]);
        foreach ($all as $itemId => $qty) {
            ItemLedger::create([
                'orderdonation_id' => $donation->id, 'item_id' => $itemId, 'pallet_id' => $source->id,
                'qty_added' => $qty, 'disposition' => 'usable', 'item_condition' => 'new', 'person_id_user' => $this->actorId,
            ]);
        }
        $source->transitionTo('empty', null, 'Training starter stock sorted');
        $this->summary['sorting_area'] = $this->describe($stock['area']);

        $tables = config('training.tables', ['A', 'B', 'C']);
        $this->summary['warehouse_pallets'] = [];
        foreach ($stock['racks'] as $p => $items) {
            $loc = $this->locations[$tables[$p % count($tables)].'-'.(intdiv($p, count($tables)) + 1)]
                ?? $this->locations[self::spotCodes()[$p % count(self::spotCodes())]];
            $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'container_type' => 'pallet', 'location_id' => $loc->id, 'datepacked' => now()->toDateString()]);
            $pallet->statuses()->create(['status' => 'sealed', 'location_id' => $loc->id, 'notes' => 'Filled and sealed (training starter stock)']);
            foreach ($items as $itemId => $qty) {
                PalletStockAssignment::create(['pallet_id' => $pallet->id, 'item_id' => $itemId, 'qty' => $qty,
                    'expires_on' => null, 'person_id_user' => $this->actorId, 'notes' => 'Training starter stock']);
            }
            $this->summary['warehouse_pallets'][] = ['id' => $pallet->id, 'tag' => $pallet->tag, 'location' => $loc->code, 'items' => $this->describe($items)];
        }
    }

    /**
     * Station 4: Ready to Fill orders of two or three lines, 1–2 each, that the goods on the W pallets can cover
     * (by item type, as orders are), leaving some on the pallets.
     */
    private function ordersToFill(array $partners, array $racks): array
    {
        $avail = [];
        foreach ($racks as $items) {
            foreach ($items as $itemId => $qty) {
                $typeId = $this->catalog[$itemId]['itemtype_id'];
                $avail[$typeId] = ($avail[$typeId] ?? 0) + $qty;
            }
        }
        $types = array_keys($avail);
        $out = [];
        $turn = 0;
        for ($i = 0; $i < config('training.stage.orders_to_fill', 3) && $types; $i++) {
            $order = $this->newOrder($partners[$i % count($partners)], 'fill-'.($i + 1));
            $lines = [];
            for ($tries = 0; count($lines) < 2 + ($i % 2) && $tries < count($types); $tries++) {
                $typeId = $types[$turn++ % count($types)];
                if (isset($lines[$typeId]) || $avail[$typeId] < 1) {
                    continue;
                }
                $qty = min(2, $avail[$typeId]);
                $avail[$typeId] -= $qty;
                $lines[$typeId] = $this->addLine($order, $typeId, $qty);
            }
            $this->readyToFill($order);
            $out[] = ['id' => $order->id, 'partner' => $order->person->organization, 'lines' => array_values($lines)];
        }

        return $out;
    }

    /** Station 5: orders already picked (from the sorting area) and Filled, their goods staged at the door. */
    private function ordersToShip(array $partners, array $loads): array
    {
        $out = [];
        foreach ($loads as $i => $items) {
            $order = $this->newOrder($partners[($i + 1) % count($partners)], 'ship-'.($i + 1));
            $lines = [];
            foreach ($items as $itemId => $qty) {
                $typeId = $this->catalog[$itemId]['itemtype_id'];
                $lines[$typeId] ??= $this->addLine($order, $typeId, 0);
                $lines[$typeId]['qty'] += $qty;
            }
            foreach ($lines as $line) {
                $order->orderLines()->whereKey($line['line_id'])->update(['qty_requested' => $line['qty']]);
            }
            $this->readyToFill($order);
            $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
            foreach ($items as $itemId => $qty) {
                ItemLedger::create([
                    'orderdonation_id' => $order->id, 'item_id' => $itemId, 'order_line_id' => $lines[$this->catalog[$itemId]['itemtype_id']]['line_id'],
                    'qty_subtracted' => $qty, 'disposition' => 'usable', 'person_id_user' => $this->actorId,
                ]);
            }
            $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLED), 'pallet_count' => 1]);
            $out[] = ['id' => $order->id, 'partner' => $order->person->organization, 'lines' => array_values($lines), 'items' => $this->describe($items)];
        }

        return $out;
    }

    private function newOrder(Person $partner, string $ref): Transaction
    {
        return Transaction::create([
            'type' => 'order', 'person_id' => $partner->id, 'person_id_user' => $this->actorId,
            'status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER), 'order_date' => now()->toDateString(),
            'source_system' => self::SOURCE, 'source_ref' => $this->batch.'-'.$ref,
        ]);
    }

    private function addLine(Transaction $order, int $itemtypeId, int $qty): array
    {
        $line = $order->orderLines()->create(['itemtype_id' => $itemtypeId, 'qty_requested' => $qty]);
        $c = collect($this->catalog)->firstWhere('itemtype_id', $itemtypeId);

        return ['line_id' => $line->id, 'itemtype_id' => $itemtypeId, 'number' => $c['number'], 'name' => $c['type'], 'qty' => $qty];
    }

    private function readyToFill(Transaction $order): void
    {
        $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_FILL),
            'fulfillment_method' => 'delivery', 'needed_by_date' => now()->addDays(3)->toDateString()]);
    }
}
