<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\DonationOffer;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\PalletStockAssignment;
use App\Models\PeopleRoles;
use App\Models\Person;
use App\Models\Role;
use App\Models\TrainingSetting;
use App\Models\Transaction;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Prepare Training: loads starter work for every station, so all teams can start at once (config/training.php):
 *
 *   1 Receiving — pending donation offers from training donors (the props "waiting to be offered" arrive against them)
 *   2 Sorting   — received donations, one R pallet each, waiting to be sorted
 *   3 Inventory — starter stock already sorted: part on sealed W pallets at TR- rack locations, the rest in the
 *                 sorting area for building new pallets
 *   4 Picking   — Ready to Fill orders from training partners
 *   5 Shipping  — Filled orders (picked from the sorting area)
 *   6 Delivery / Ordering — a "Team N Driver" per team (phone + PIN for the Driver Portal) with a Shipped order
 *                 each, Delivered orders whose signed BOLs wait for review, and the training partners for Order Entry
 *
 * Writes go through the real models (Pallet::transitionTo, DonationOffer::transitionTo, Transaction statuses) in
 * one transaction, recorded as the person who pressed the button, tagged source_system = 'training', with
 * ActivityRecorder paused (starter data isn't anyone's training work). Running it again adds another set.
 * Refused unless training mode is on, so it can't be pressed on a live instance by accident. The returned summary
 * (also saved on training_settings.prepared_summary) is what the setup packet prints.
 */
class TrainingPreparer
{
    public const SOURCE = 'training';

    /** Placeholder signed-BOL image (1x1 PNG), as the simulator uses. */
    private const BOL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private int $actorId;

    private string $batch;

    /** @var array<int, array{item_id: int, itemtype_id: int, number: string, name: string, qty: int}> */
    private array $catalog = [];

    /** @var array<int, int> item_id => qty still in the sorting area */
    private array $pool = [];

    private array $locations = [];

    private array $summary = [];

    private int $pick = 0;

    public function run(): array
    {
        if (! TrainingMode::enabled()) {
            throw new RuntimeException('Turn Training Mode on before preparing training data.');
        }
        $this->actorId = (int) Auth::id();
        $this->batch = now()->format('YmdHis');

        $summary = ActivityRecorder::paused(fn () => DB::transaction(function () {
            $this->buildCatalog();
            $this->buildLocations();
            $donors = $this->buildDonors();
            $partners = $this->buildPartners();
            $drivers = $this->buildDrivers();

            $this->summary['offers'] = $this->offers($donors);
            $this->summary['pallets_to_sort'] = $this->palletsToSort($donors);
            $this->stockShelves($donors[0]);
            $this->summary['orders_to_fill'] = $this->orders($partners, config('training.counts.orders_to_fill', 10), 'fill');
            $this->summary['orders_to_ship'] = $this->orders($partners, config('training.counts.orders_to_ship', 10), 'ship');
            $this->summary['team_drivers'] = $drivers;
            $this->summary['orders_to_deliver'] = $this->deliveries($partners, $drivers);
            $this->summary['orders_to_review'] = $this->orders($partners, config('training.counts.orders_to_review', 5), 'review');
            $this->summary['sorting_area'] = $this->poolSummary();
            $this->summary['partners'] = array_map(fn (Person $p) => $p->organization, $partners);
            $this->summary['prepared_at'] = now()->toIso8601String();

            return $this->summary;
        }));

        TrainingSetting::current()->update([
            'prepared_at' => now(), 'prepared_by' => $this->actorId, 'prepared_summary' => $summary,
        ]);

        return $summary;
    }

    private function buildCatalog(): void
    {
        $missing = [];
        foreach (config('training.items', []) as $entry) {
            // The displayed form ("180", "950-11"): parseNumber() leaves a short family unpadded, and a family's
            // default item shows no variant (stored "00").
            [$family, $variant] = ItemType::parseNumber((string) $entry['number']);
            $family = ctype_digit($family) ? str_pad($family, 4, '0', STR_PAD_LEFT) : $family;
            $type = ItemType::with('items')->where('family', $family)->where('active', true)
                ->where(fn ($q) => $variant === null ? $q->where('variant', '00')->orWhereNull('variant') : $q->where('variant', $variant))
                ->first();
            $item = $type?->items->first();
            if (! $item) {
                $missing[] = $entry['number'];

                continue;
            }
            $this->catalog[$item->id] = [
                'item_id' => $item->id, 'itemtype_id' => $type->id, 'number' => $type->display_number,
                'name' => $type->name, 'qty' => (int) $entry['qty'],
            ];
        }
        if (! $this->catalog) {
            throw new RuntimeException('None of the item numbers in config/training.php are in this instance\'s Master Item List.');
        }
        $this->summary['missing_items'] = $missing;
    }

    private function buildLocations(): void
    {
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Main Warehouse', 'pallets_enabled' => true]);
        $codes = ['TR-DOCK' => [], 'TR-SORT' => []];
        foreach (range(1, 8) as $bay) {
            $codes[sprintf('TR-A-%02d', $bay)] = ['Aisle' => 'TR-A', 'Lane' => sprintf('%02d', $bay), 'PullSequence' => 9000 + $bay * 10];
        }
        foreach ($codes as $code => $extra) {
            $location = Location::where('code', $code)->first();
            if (! $location) {
                $location = new Location(['code' => $code, 'status' => 'active'] + $extra);
                $location->warehouse_id = $warehouse->id;
                $location->save();
            }
            $this->locations[$code] = $location;
        }
        $this->summary['locations'] = array_keys($codes);
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

    private function offers(array $donors): array
    {
        $descriptions = ['Pallet of bottled water', 'Boxes of canned food', 'Cleaning supplies and flood buckets',
            'Hygiene items (soap, toothpaste, wipes)', 'Paper goods (toilet paper, paper towels)', 'Tarps and work gloves'];
        $out = [];
        for ($i = 0; $i < config('training.counts.offers', 5); $i++) {
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

    private function palletsToSort(array $donors): array
    {
        $dock = $this->locations['TR-DOCK'];
        $out = [];
        for ($i = 0; $i < config('training.counts.pallets_to_sort', 10); $i++) {
            $donor = $donors[$i % count($donors)];
            $donation = Transaction::create([
                'type' => 'donation', 'category' => 'donation', 'person_id' => $donor->id, 'person_id_user' => $this->actorId,
                'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED), 'order_date' => now()->toDateString(),
                'container_count' => 1, 'container_types' => ['pallet'], 'container_type_counts' => ['pallet' => 1],
                'arrival_method' => 'box_truck', 'manifest' => '1 pallet — mixed goods (training)',
                'source_system' => self::SOURCE, 'source_ref' => $this->batch.'-sort-'.($i + 1),
            ]);
            $pallet = Pallet::create([
                'kind' => 'R', 'status' => 'received', 'container_type' => 'pallet', 'donor_person_id' => $donor->id,
                'orderdonation_id' => $donation->id, 'location_id' => $dock->id, 'datepacked' => now()->toDateString(),
            ]);
            $pallet->statuses()->create(['status' => 'received', 'location_id' => $dock->id, 'notes' => 'Training starter pallet']);
            $out[] = ['donation_id' => $donation->id, 'pallet_id' => $pallet->id, 'tag' => $pallet->tag, 'donor' => $donor->organization];
        }

        return $out;
    }

    /** Starter stock: one sorted donation holding every catalog item, part of it put on sealed W pallets. */
    private function stockShelves(Person $donor): void
    {
        $donation = Transaction::create([
            'type' => 'donation', 'category' => 'donation', 'person_id' => $donor->id, 'person_id_user' => $this->actorId,
            'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE), 'order_date' => now()->toDateString(),
            'container_count' => 1, 'container_types' => ['pallet'], 'container_type_counts' => ['pallet' => 1],
            'manifest' => 'Training starter stock', 'source_system' => self::SOURCE, 'source_ref' => $this->batch.'-stock',
        ]);
        $source = Pallet::create([
            'kind' => 'R', 'status' => 'received', 'container_type' => 'pallet', 'donor_person_id' => $donor->id,
            'orderdonation_id' => $donation->id, 'location_id' => $this->locations['TR-SORT']->id, 'datepacked' => now()->toDateString(),
        ]);
        $source->statuses()->create(['status' => 'received', 'location_id' => $this->locations['TR-SORT']->id]);
        foreach ($this->catalog as $itemId => $entry) {
            ItemLedger::create([
                'orderdonation_id' => $donation->id, 'item_id' => $itemId, 'pallet_id' => $source->id,
                'qty_added' => $entry['qty'], 'disposition' => 'usable', 'item_condition' => 'new', 'person_id_user' => $this->actorId,
            ]);
            $this->pool[$itemId] = $entry['qty'];
        }
        $source->transitionTo('empty', null, 'Training starter stock sorted');

        // Sealed W pallets, two or three items each, at the TR- racks.
        $racks = array_values(array_filter($this->locations, fn ($l, $code) => str_starts_with($code, 'TR-A-'), ARRAY_FILTER_USE_BOTH));
        $share = (float) config('training.on_pallets_share', 0.5);
        $itemIds = array_keys($this->catalog);
        $count = max(1, (int) config('training.counts.warehouse_pallets', 6));
        $pallets = [];
        foreach (range(0, $count - 1) as $p) {
            $loc = $racks[$p % count($racks)];
            $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'container_type' => 'pallet', 'location_id' => $loc->id, 'datepacked' => now()->toDateString()]);
            $pallet->statuses()->create(['status' => 'sealed', 'location_id' => $loc->id, 'notes' => 'Filled and sealed (training starter stock)']);
            $pallets[$p] = ['pallet' => $pallet, 'location' => $loc->code, 'items' => []];
        }
        foreach ($itemIds as $k => $itemId) {
            $put = (int) floor($this->catalog[$itemId]['qty'] * $share);
            if ($put <= 0) {
                continue;
            }
            $slot = $k % $count;
            PalletStockAssignment::create(['pallet_id' => $pallets[$slot]['pallet']->id, 'item_id' => $itemId, 'qty' => $put,
                'expires_on' => null, 'person_id_user' => $this->actorId, 'notes' => 'Training starter stock']);
            $this->pool[$itemId] -= $put;
            $pallets[$slot]['items'][] = ['number' => $this->catalog[$itemId]['number'], 'name' => $this->catalog[$itemId]['name'], 'qty' => $put];
        }
        $this->summary['warehouse_pallets'] = [];
        foreach ($pallets as $entry) {
            $this->summary['warehouse_pallets'][] = ['id' => $entry['pallet']->id, 'tag' => $entry['pallet']->tag, 'location' => $entry['location'], 'items' => $entry['items']];
        }
    }

    /**
     * Training orders at a given stage. 'fill' stops at Ready to Fill; 'ship' is picked from the sorting area and
     * Filled; 'review' is also Delivered with a placeholder signed BOL, waiting for review.
     */
    private function orders(array $partners, int $count, string $stage): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $partner = $partners[$i % count($partners)];
            $order = $this->newOrder($partner, $stage.'-'.($i + 1));
            $lines = $this->addLines($order, $stage !== 'fill');
            if ($stage !== 'fill') {
                $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
                $this->pickFromPool($order, $lines);
                $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLED), 'pallet_count' => 1]);
            }
            if ($stage === 'review') {
                $this->shipAndDeliver($order, null);
            }
            $out[] = ['id' => $order->id, 'partner' => $partner->organization, 'lines' => $lines];
        }

        return $out;
    }

    /** One Shipped order per team driver, for that team's Driver Portal delivery. */
    private function deliveries(array $partners, array $drivers): array
    {
        $out = [];
        foreach ($drivers as $i => $driver) {
            $partner = $partners[$i % count($partners)];
            $order = $this->newOrder($partner, 'deliver-team-'.$driver['team']);
            $lines = $this->addLines($order, true);
            $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
            $this->pickFromPool($order, $lines);
            $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLED), 'pallet_count' => 1]);
            $order->update(['driver_id' => $driver['id'], 'status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_SHIP),
                'bol_number' => 'BOL-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
            $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_SHIPPED)]);
            $out[] = ['id' => $order->id, 'team' => $driver['team'], 'partner' => $partner->organization, 'lines' => $lines];
        }

        return $out;
    }

    private function newOrder(Person $partner, string $ref): Transaction
    {
        $order = Transaction::create([
            'type' => 'order', 'person_id' => $partner->id, 'person_id_user' => $this->actorId,
            'status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER), 'order_date' => now()->toDateString(),
            'source_system' => self::SOURCE, 'source_ref' => $this->batch.'-'.$ref,
        ]);

        return $order;
    }

    /** Two or three lines of 2–6 units, from items that can cover them (from the sorting area if $fromPool). */
    private function addLines(Transaction $order, bool $fromPool): array
    {
        $itemIds = array_keys($this->catalog);
        $lines = [];
        $want = 2 + ($this->pick % 2);
        for ($tries = 0; count($lines) < $want && $tries < count($itemIds) * 2; $tries++) {
            $itemId = $itemIds[($this->pick++) % count($itemIds)];
            $qty = 2 + ($this->pick % 5);
            if (isset($lines[$itemId]) || ($fromPool && ($this->pool[$itemId] ?? 0) < $qty)) {
                continue;
            }
            $line = $order->orderLines()->create(['itemtype_id' => $this->catalog[$itemId]['itemtype_id'], 'qty_requested' => $qty]);
            $lines[$itemId] = ['line_id' => $line->id, 'item_id' => $itemId, 'number' => $this->catalog[$itemId]['number'],
                'name' => $this->catalog[$itemId]['name'], 'qty' => $qty];
        }
        $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_FILL),
            'fulfillment_method' => 'delivery', 'needed_by_date' => now()->addDays(3)->toDateString()]);

        return array_values($lines);
    }

    private function pickFromPool(Transaction $order, array $lines): void
    {
        foreach ($lines as $line) {
            ItemLedger::create([
                'orderdonation_id' => $order->id, 'item_id' => $line['item_id'], 'order_line_id' => $line['line_id'],
                'qty_subtracted' => $line['qty'], 'disposition' => 'usable', 'person_id_user' => $this->actorId,
            ]);
            $this->pool[$line['item_id']] -= $line['qty'];
        }
    }

    private function shipAndDeliver(Transaction $order, ?int $driverId): void
    {
        $order->update(['driver_id' => $driverId, 'status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_SHIP),
            'bol_number' => 'BOL-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
        $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_SHIPPED)]);
        $path = 'signed-bols/training-'.$order->id.'.png';
        Storage::disk('local')->put($path, base64_decode(self::BOL_PNG));
        $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_DELIVERED), 'signed_bol_path' => $path]);
    }

    private function poolSummary(): array
    {
        $out = [];
        foreach ($this->pool as $itemId => $qty) {
            if ($qty > 0) {
                $out[] = ['number' => $this->catalog[$itemId]['number'], 'name' => $this->catalog[$itemId]['name'], 'qty' => $qty];
            }
        }

        return $out;
    }
}
