<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Simulator;

use App\Models\County;
use App\Models\DonationOffer;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Pallet;
use App\Models\PalletStockAssignment;
use App\Models\PeopleRoles;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\FirstRunService;
use App\Services\PickPlanner;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Warehouse simulator: replays a year (or any number of days) of plausible
 * warehouse life into an EMPTY instance so the GUI, reports and data entry can
 * be tested against realistic volume — formatting at length, sort order,
 * pagination gaps, report math, query performance.
 *
 * It works day by day, oldest first. Each day brings donations (Receiving),
 * sorting of earlier donations' pallets into usable/outdated/trashed/diverted
 * ledger lines, put-away onto warehouse pallets, new partner requests, and
 * every later lifecycle step of earlier orders (fill, ship, deliver, BOL
 * review). All of it goes through the same models/transition methods the
 * controllers use, with Carbon::setTestNow() pointed at the simulated moment,
 * so created_at/status_changed_at/pallet history/status logs carry real
 * historical timestamps without any raw-SQL backdating.
 *
 * Stock is tracked in memory as the run goes: a fill is only ever as large as
 * what's on hand at that moment, so on-hand never goes negative — except for a
 * few deliberately injected over-picks (a picker keys more than the shelf
 * held), returned in the summary, so the app's handling of negative stock can
 * be exercised on purpose rather than by accident.
 *
 * Everything runs in ONE database transaction: a failure leaves the instance
 * exactly as it was. Randomness is seeded (SimRandom), so a seed reproduces a
 * run. Simulated people carry source_system = 'simulator'.
 *
 * Not simulated yet: volunteer kiosk sign-ins, feedback reports, forms
 * submissions, print jobs, offline-mode, user logins.
 */
class WarehouseSimulator
{
    public const SOURCE = 'simulator';

    // Events due on the same day are processed in this order, each phase
    // starting at its own clock hour — so a sort always precedes a fill on the
    // same day (stock exists before it is picked) and one order's same-day
    // steps (fill -> complete -> assign -> ship) are in increasing time.
    private const PHASES = ['sort', 'putaway', 'fill', 'complete_fill', 'assign', 'ship', 'deliver', 'bol_review', 'bol_reupload', 'move'];

    private const PHASE_HOUR = ['sort' => 9, 'putaway' => 11, 'fill' => 12, 'complete_fill' => 14, 'assign' => 15, 'ship' => 16, 'deliver' => 10, 'bol_review' => 11, 'bol_reupload' => 10, 'move' => 13];

    // Tuned so a default run fills ~90% of requested units: enough shortages
    // for the unfulfilled report to have something to say, not so many that
    // half the orders are empty.
    private const SUPPLY_TUNING = 2.5;

    // 1x1 transparent PNG, stands in for the driver's photographed BOL.
    private const BOL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private SimRandom $rand;

    private NameBank $names;

    private Carbon $now;

    private string $today;

    private int $dayIndex = 0;

    private int $adminId;

    private ?int $unknownDonorId = null;

    private string $tz;

    /** @var array<string, int> */
    private array $statusIds = [];

    /** @var array<int, array{itemtype_id:int,item_id:int,name:string,category_id:?int,w_supply:float,w_demand:float}> keyed by item_id */
    private array $catalog = [];

    /** @var array<int|string, array<int, int>> category_id => item_ids */
    private array $byCategory = [];

    /** @var array<int, int> item_id => usable stock on hand */
    private array $stock = [];

    /** @var array<int, int> item_id => usable stock still in the sorting area (sorted, not yet on a pallet); may go negative on an injected over-pick */
    private array $pool = [];

    /** @var array<int, array{tag:string,status:string,location:?string,sequence:?int,items:array<int,int>}> warehouse pallet id => state, mirroring the DB */
    private array $pallets = [];

    /** @var array<int, array<int, true>> item_id => [pallet id => true] for the warehouse pallets holding it */
    private array $palletsByItem = [];

    /** @var array<int, array<string, mixed>> */
    private array $donors = [];

    /** @var array<int, array<string, mixed>> */
    private array $partners = [];

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    /** @var array<int, int> */
    private array $inboundDrivers = [];

    /** @var array<int, int> */
    private array $outboundDrivers = [];

    /** @var array<int, int> partner person id => driver id */
    private array $pickupDrivers = [];

    /** @var array<string, Location> */
    private array $locations = [];

    /** @var array<int, Location> */
    private array $racks = [];

    /** @var array<string, array<string, array<int, array<string, mixed>>>> date => phase => events */
    private array $queue = [];

    /** @var array<int, int> */
    private array $errorDays = [];

    /** @var array<int, array<string, mixed>> */
    private array $injected = [];

    private int $totalDays = 0;

    /** Index into PHASES of the phase being processed today (-1 before the first). */
    private int $phaseIndex = -1;

    private float $supplyScale = 1.0;

    /** @var array<int, array{0:int,1:int}> [start day index, length] of the disaster surges */
    private array $surges = [];

    private int $requestedUnits = 0;

    private int $filledUnits = 0;

    private int $counter = 0;

    /** @var (Closure(int, int, string): void)|null */
    private ?Closure $progress = null;

    /**
     * Why the simulator may not run here, or null if the instance is empty
     * enough. The test is "has anyone entered operational data": any
     * donation/order/pallet/ledger/driver/offer/location, or any person other
     * than the system placeholder and people holding a role (the staff
     * accounts setup creates).
     */
    public static function blockedReason(): ?string
    {
        foreach (['orderdonations' => 'donations/orders', 'pallets' => 'pallets', 'item_ledgers' => 'inventory movements', 'drivers' => 'drivers', 'donation_offers' => 'donation offers', 'locations' => 'locations'] as $table => $label) {
            if (DB::table($table)->exists()) {
                return "This instance already has {$label}. The simulator only runs on a brand-new instance with no data.";
            }
        }

        $hasPeople = DB::table('people')
            ->whereNull('system_key')
            ->whereNotIn('id', fn ($q) => $q->select('person_id')->from('people_roles'))
            ->exists();
        if ($hasPeople) {
            return 'This instance already has donors/partners. The simulator only runs on a brand-new instance with no data.';
        }

        if (DB::table('setup_state')->whereNotNull('demo_data_seeded_at')->exists()) {
            return 'Example or simulated data was already loaded on this instance.';
        }

        return null;
    }

    /** What the run needs that blockedReason() doesn't cover (essentials seeded, an administrator). */
    public static function missingRequirement(): ?string
    {
        if (! DB::table('itemtypes')->exists()) {
            return 'The item catalog has not been seeded yet (php artisan setup:seed-essentials).';
        }
        if (! PeopleRoles::where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists()) {
            return 'There is no administrator yet — finish setup (or run admin:bootstrap) first.';
        }

        return null;
    }

    /**
     * @param  int  $days  how far back to start; the run covers [today-days, yesterday]
     * @param  int  $orders  approximate total orders (Poisson, so +/- a few percent)
     * @param  int  $errors  how many negative-stock over-picks to inject
     * @param  (Closure(int, int, string): void)|null  $progress  (day, totalDays, message)
     * @return array<string, mixed> summary
     */
    public function run(int $days = 365, int $orders = 1200, ?int $seed = null, int $errors = 4, ?Closure $progress = null): array
    {
        if ($reason = self::blockedReason() ?? self::missingRequirement()) {
            throw new RuntimeException($reason);
        }

        $seed ??= random_int(1, 2_000_000_000);
        $this->progress = $progress;
        $started = microtime(true);

        try {
            // Simulated logins go to the accounts schema (MULTI_WAREHOUSE.md §6): a failure must undo them too.
            $summary = Model::unguarded(fn () => DB::connection('accounts')->transaction(
                fn () => DB::transaction(fn () => $this->simulate($days, $orders, $seed, $errors))
            ));
        } finally {
            Carbon::setTestNow();
        }

        $summary['seconds'] = round(microtime(true) - $started, 1);

        return $summary;
    }

    // ------------------------------------------------------------------
    // The run
    // ------------------------------------------------------------------

    private function simulate(int $days, int $orderTarget, int $seed, int $errors): array
    {
        $this->tz = (string) config('app.timezone', 'UTC');
        $this->rand = new SimRandom($seed);
        $this->names = new NameBank($this->rand);
        $this->adminId = (int) PeopleRoles::where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->value('person_id');
        $this->unknownDonorId = Person::where('system_key', 'unknown-donor')->value('id');

        $start = Carbon::now($this->tz)->startOfDay()->subDays($days);
        $this->today = $start->toDateString();
        $this->now = $start->copy()->subDay()->setTime(8, 0);
        Carbon::setTestNow($this->now);

        $donationTarget = max(1, (int) round($orderTarget * 0.4));
        $this->buildCatalog();
        $this->buildLocations();
        $this->buildPeople();
        $this->buildDrivers();

        $this->totalDays = $days;
        $this->surges = [
            [$this->rand->int((int) ($days * 0.25), (int) ($days * 0.45)), 10],
            [$this->rand->int((int) ($days * 0.60), (int) ($days * 0.88)), 12],
        ];
        $orderWeights = $this->dayWeights($start, $days, orders: true);
        $donationWeights = $this->dayWeights($start, $days, orders: false);
        $orderWeightSum = array_sum($orderWeights);
        $donationWeightSum = array_sum($donationWeights);

        // Supply per donation scales with how much demand each donation has to
        // cover, so --orders/--days changes keep the fill rate in the same range.
        $this->supplyScale = self::SUPPLY_TUNING * ($orderTarget / $donationTarget) / 2.5;

        $lo = (int) floor($days * 0.35);
        $hi = max($lo, (int) floor($days * 0.95) - 1);
        $this->errorDays = array_slice($this->rand->pickMany(range($lo, $hi), min($errors, $hi - $lo + 1)), 0, $errors);
        sort($this->errorDays);

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $this->today = $day->toDateString();
            $this->dayIndex = $i;
            $this->progress?->__invoke($i, $days, $this->today);

            $this->phaseIndex = -1;
            $this->at(7);
            for ($n = $this->rand->poisson($donationTarget * $donationWeights[$i] / $donationWeightSum); $n > 0; $n--) {
                $this->receive();
            }
            if ($this->rand->chance(0.05)) {
                $this->standaloneOffer();
            }

            // The forklift clears the sorting area once a day, Monday to Saturday.
            if ($day->dayOfWeek !== 0) {
                $this->queue[$this->today]['putaway'][] = [];
            }

            $this->runPhase('sort');

            $this->phaseIndex = 0; // sorting is done for the day; new orders can still be filled today
            $this->at(10);
            for ($n = $this->rand->poisson($orderTarget * $orderWeights[$i] / $orderWeightSum); $n > 0; $n--) {
                $this->createOrder();
            }

            foreach (self::PHASES as $phase) {
                if ($phase !== 'sort') {
                    $this->runPhase($phase);
                }
            }
            unset($this->queue[$this->today]);
        }

        Carbon::setTestNow();
        $this->progress?->__invoke($days, $days, 'Done');

        return $this->summary($seed, $start, $days);
    }

    /** Relative activity per day: weekday rhythm, two disaster surges, and slow growth over the period. */
    private function dayWeights(Carbon $start, int $days, bool $orders): array
    {
        $weights = [];
        for ($i = 0; $i < $days; $i++) {
            $dow = (int) $start->copy()->addDays($i)->dayOfWeek; // 0 = Sunday
            $base = $orders
                ? [0 => 0.05, 6 => 0.25][$dow] ?? 1.0
                : [0 => 0.0, 6 => 0.5][$dow] ?? 1.0;
            $surge = 1.0;
            foreach ($this->surges as [$at, $len]) {
                if ($i >= $at && $i < $at + $len) {
                    $surge = $orders ? 3.0 : 2.2;
                }
            }
            $weights[$i] = $base * $surge * (0.6 + 0.6 * $i / max(1, $days));
        }

        return $weights;
    }

    // ------------------------------------------------------------------
    // Clock
    // ------------------------------------------------------------------

    /**
     * Start something no earlier than $hour today. The clock never runs
     * backwards within a day: a busy day's sorting can run past noon, and fills
     * must still be stamped after the stock they pick was sorted.
     */
    private function at(int $hour, int $minute = 0): void
    {
        $target = Carbon::parse($this->today.' 00:00:00', $this->tz)->setTime($hour, $minute, $this->rand->int(0, 59));
        if ($this->now->toDateString() !== $this->today || $this->now->lt($target)) {
            $this->now = $target;
        }
        Carbon::setTestNow($this->now);
    }

    private function tick(int $minSeconds = 20, int $maxSeconds = 180): void
    {
        $next = $this->now->copy()->addSeconds($this->rand->int($minSeconds, $maxSeconds));
        if ($next->toDateString() !== $this->today) {
            $next = Carbon::parse($this->today.' 23:59:59', $this->tz);
        }
        $this->now = $next;
        Carbon::setTestNow($this->now);
    }

    /** Run something as of another moment (an earlier offer call), then put the clock back. */
    private function asOf(Carbon $moment, Closure $fn): mixed
    {
        Carbon::setTestNow($moment);
        try {
            return $fn();
        } finally {
            Carbon::setTestNow($this->now);
        }
    }

    // ------------------------------------------------------------------
    // Event queue
    // ------------------------------------------------------------------

    private function schedule(int $inDays, string $phase, array $payload = []): void
    {
        // Today's phases run in a fixed order; an event for a phase that has already run today would be
        // silently dropped, so it moves to tomorrow instead.
        if ($inDays === 0 && array_search($phase, self::PHASES, true) <= $this->phaseIndex) {
            $inDays = 1;
        }
        $date = Carbon::parse($this->today)->addDays($inDays)->toDateString();
        $this->queue[$date][$phase][] = $payload;
    }

    /** Offset in days from a weighted table: [days => weight]. */
    private function lag(array $table): int
    {
        return (int) $this->rand->weighted($table);
    }

    private function runPhase(string $phase): void
    {
        $this->phaseIndex = (int) array_search($phase, self::PHASES, true);
        $this->at(self::PHASE_HOUR[$phase]);
        while (! empty($this->queue[$this->today][$phase])) {
            $payload = array_shift($this->queue[$this->today][$phase]);
            match ($phase) {
                'sort' => $this->sortPallet($payload),
                'fill' => $this->fillOrder($payload['order']),
                'complete_fill' => $this->completeFilling($payload['order']),
                'assign' => $this->assignDriver($payload['order']),
                'ship' => $this->markShipped($payload['order']),
                'deliver' => $this->deliver($payload['order']),
                'bol_review' => $this->reviewBol($payload['order']),
                'bol_reupload' => $this->reuploadBol($payload['order']),
                'putaway' => $this->putAwayDay(),
                'move' => $this->movePallet($payload['pallet']),
            };
            $phase === 'sort' ? $this->tick(5, 30) : $this->tick(30, 240);
        }
    }

    private function status(string $name): int
    {
        return $this->statusIds[$name] ??= Transaction::statusId($name);
    }

    // ------------------------------------------------------------------
    // Reference data
    // ------------------------------------------------------------------

    private function buildCatalog(): void
    {
        $types = ItemType::with('items')
            ->where('status', 'orderable')
            ->where('active', true)
            ->whereHas('items')
            ->orderBy('id')
            ->get()
            ->groupBy('category_id');

        $picked = [];
        foreach ($types as $group) {
            foreach ($this->rand->pickMany($group->all(), 7) as $type) {
                $picked[] = $type;
            }
        }
        if (! $picked) {
            throw new RuntimeException('There are no orderable item types to simulate with.');
        }
        $this->rand->shuffle($picked);

        foreach ($picked as $rank => $type) {
            $item = $type->items->first();
            $popularity = 1 / (($rank + 1) ** 0.7);
            $this->catalog[$item->id] = [
                'itemtype_id' => $type->id,
                'item_id' => $item->id,
                'name' => $type->name,
                'category_id' => $type->category_id,
                'w_supply' => $popularity,
                // Demand deviates from supply per item (log-normal), so some items run chronically short.
                'w_demand' => $popularity * exp($this->gauss() * 0.7),
            ];
            $this->byCategory[$type->category_id ?? 0][] = $item->id;
            $this->stock[$item->id] = 0;
        }
    }

    private function gauss(): float
    {
        return sqrt(-2 * log(max(1e-12, $this->rand->float()))) * cos(2 * M_PI * $this->rand->float());
    }

    private function buildLocations(): void
    {
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Main Warehouse', 'pallets_enabled' => true]);

        $make = function (string $code, array $extra = []) use ($warehouse): Location {
            $location = new Location(['code' => $code, 'status' => 'active'] + $extra);
            $location->warehouse_id = $warehouse->id;
            $location->save();

            return $location;
        };

        foreach (['DOCK-IN', 'DOCK-OUT', 'SORT-1', 'SORT-2', 'HOLD-1'] as $code) {
            $this->locations[$code] = $make($code);
        }

        foreach (range('A', 'F') as $a => $aisle) {
            foreach (range(1, 12) as $bay) {
                $code = sprintf('%s-%02d', $aisle, $bay);
                $this->racks[] = $make($code, ['Aisle' => $aisle, 'Lane' => sprintf('%02d', $bay), 'PullSequence' => $a * 100 + $bay]);
            }
        }
    }

    private function buildPeople(): void
    {
        $this->at(9);
        $roleDonor = 2;
        $rolePartner = 1;

        $emails = [];
        $email = function (string $slug) use (&$emails): string {
            $emails[$slug] = ($emails[$slug] ?? 0) + 1;

            return $slug.($emails[$slug] > 1 ? $emails[$slug] : '').'@example.'.($this->rand->chance(0.5) ? 'org' : 'com');
        };

        $contactFor = function (Person $org, string $domainSlug) use ($email) {
            $first = $this->names->firstName();
            $last = $this->names->lastName();

            return Person::create([
                'first_name' => $first, 'last_name' => $last,
                'email' => $email(strtolower($first.'.'.$last).'.'.$domainSlug),
                'phone' => $this->names->phone(),
                'parent_person_id' => $org->id, 'contact_role' => 'Primary',
                'organization' => $org->organization,
                'source_system' => self::SOURCE, 'source_ref' => 'contact-'.(++$this->counter),
            ]);
        };

        // Donors: businesses, congregations/clubs, individuals.
        for ($i = 0; $i < 90; $i++) {
            $kind = $this->rand->weighted(['business' => 50, 'group' => 25, 'individual' => 25]);
            $place = $this->names->place();
            $county = $this->county($place['county']);
            $data = ['address' => $place['address'], 'city' => $place['city'], 'state' => 'NC', 'zip' => $place['zip'], 'county_id' => $county, 'phone' => $this->names->phone(), 'source_system' => self::SOURCE, 'source_ref' => 'donor-'.($i + 1)];

            if ($kind === 'individual') {
                $first = $this->names->firstName();
                $last = $this->names->lastName();
                $person = Person::create($data + ['first_name' => $first, 'last_name' => $last, 'email' => $email(strtolower($first.$last))]);
                $contact = null;
            } else {
                $name = $kind === 'business' ? $this->names->businessName() : $this->names->partnerName();
                $person = Person::create($data + ['organization' => $name, 'is_organization' => true, 'first_name' => null, 'last_name' => null, 'email' => $email('donations.'.$this->names->slug($name))]);
                $contact = $contactFor($person, $this->names->slug($name));
            }
            PeopleRoles::create(['person_id' => $person->id, 'role_id' => $roleDonor]);

            $this->donors[] = [
                'id' => $person->id, 'kind' => $kind, 'contact_id' => $contact?->id,
                'address' => $place['address'], 'city' => $place['city'], 'zip' => $place['zip'],
                // Heavy-tailed: a few donors give most of the volume.
                'weight' => 1 / (($i + 1) ** 0.6),
                'quality' => $kind === 'business' ? 0.9 + $this->rand->float() * 0.09 : 0.75 + $this->rand->float() * 0.2,
                'new_share' => $kind === 'business' ? 0.7 : ($kind === 'group' ? 0.3 : 0.1),
            ];
        }
        $this->rand->shuffle($this->donors);

        // Partners (distribution points that place requests).
        for ($i = 0; $i < 40; $i++) {
            $place = $this->names->place();
            $name = $this->names->partnerName();
            $person = Person::create([
                'organization' => $name, 'is_organization' => true,
                'address' => $place['address'], 'city' => $place['city'], 'state' => 'NC', 'zip' => $place['zip'],
                'county_id' => $this->county($place['county']), 'phone' => $this->names->phone(),
                'email' => $email('requests.'.$this->names->slug($name)),
                'source_system' => self::SOURCE, 'source_ref' => 'partner-'.($i + 1),
            ]);
            $contact = $contactFor($person, $this->names->slug($name));
            PeopleRoles::create(['person_id' => $person->id, 'role_id' => $rolePartner]);

            // Most are approved; a few are pending/denied/blocked so the partner-status views have variety.
            $path = $this->rand->weighted(['approved' => 84, 'pending' => 6, 'denied' => 4, 'blocked' => 6]);
            $person->transitionPartnerStatus(Person::PARTNER_STATUS_PENDING, $this->adminId, 'Application received');
            if ($path !== 'pending') {
                $person->transitionPartnerStatus($path === 'denied' ? 'denied' : 'approved', $this->adminId, $path === 'denied' ? 'Outside service area' : 'Approved after review');
            }
            if ($path === 'blocked') {
                $person->transitionPartnerStatus('blocked', $this->adminId, 'Repeated missed pickups');
            }
            $person->forceFill(['approved_at' => $path === 'pending' ? null : $this->now, 'email_verified_at' => $this->now])->save();

            if (in_array($path, ['approved', 'blocked'], true)) {
                $this->partners[] = [
                    'id' => $person->id, 'contact' => $contact->full_name, 'phone' => $contact->phone,
                    'size' => 0.5 + $this->rand->float() * 2.0,
                    'weight' => $path === 'blocked' ? 0.0 : 1 / (($i + 1) ** 0.5),
                    'ordering' => $path === 'approved',
                ];
            }
        }
        $this->partners = array_values(array_filter($this->partners, fn ($p) => $p['ordering']));
    }

    private function county(string $name): int
    {
        return County::firstOrCreate(['county' => $name, 'state' => 'NC'])->id;
    }

    private function buildDrivers(): void
    {
        $carriers = ['UPS Freight', 'FedEx Freight', 'Old Dominion', 'Carolina Haulers', 'XPO', 'Local box truck'];
        for ($i = 0; $i < 25; $i++) {
            $this->inboundDrivers[] = Driver::create(['name' => $this->names->firstName().' '.$this->names->lastName(), 'phone' => $this->names->phone(), 'carrier' => $this->rand->pick($carriers)])->id;
        }
        foreach (range(1, 6) as $i) {
            $this->outboundDrivers[] = Driver::create(['name' => $this->names->firstName().' '.$this->names->lastName(), 'phone' => $this->names->phone(), 'carrier' => 'Volunteer driver'])->id;
        }
    }

    // ------------------------------------------------------------------
    // Donations: receive, offers, sort, put away, move
    // ------------------------------------------------------------------

    private function pickDonor(): array
    {
        $weights = [];
        foreach ($this->donors as $i => $d) {
            $weights[$i] = $d['weight'];
        }

        return $this->donors[$this->rand->weighted($weights)];
    }

    private function receive(): void
    {
        $this->tick(60, 400);

        $unknown = $this->unknownDonorId && $this->rand->chance(0.06);
        $donor = $unknown ? null : $this->pickDonor();
        $category = $this->rand->weighted(['donation' => 88, 'supplies' => 4, 'equipment' => 3, 'other' => 5]);
        $kind = $donor['kind'] ?? 'individual';

        $arrival = $this->rand->weighted(match ($kind) {
            'business' => ['semi' => 30, 'box_truck' => 30, 'delivery_truck' => 20, 'trailer' => 5, 'other' => 5, 'personal_vehicle' => 10],
            'group' => ['personal_vehicle' => 55, 'box_truck' => 35, 'trailer' => 10],
            default => ['personal_vehicle' => 85, 'other' => 15],
        });
        $freight = in_array($arrival, ['semi', 'box_truck', 'delivery_truck', 'trailer'], true);

        // Containers: freight is mostly palletized; everyone else brings boxes/bags/totes/loose.
        if ($category !== 'donation') {
            $types = ['box'];
            $counts = ['box' => $this->rand->int(1, 4)];
        } elseif ($freight && $this->rand->chance(0.75)) {
            $types = ['pallet'];
            $counts = ['pallet' => (int) $this->rand->weighted([1 => 30, 2 => 25, 3 => 18, 4 => 12, 5 => 8, 6 => 7])];
        } else {
            $types = $this->rand->pickMany(['box', 'bag', 'tote', 'loose'], $this->rand->int(1, 3));
            $counts = [];
            foreach ($types as $t) {
                if ($t !== 'loose') {
                    $counts[$t] = $this->rand->int(1, 5);
                }
            }
            if (! $counts) {
                $types[] = 'box';
                $counts['box'] = $this->rand->int(1, 3);
            }
        }
        $containerCount = array_sum($counts);

        $theme = $this->rand->weighted(match ($kind) {
            'business' => ['single' => 40, 'themed' => 40, 'mixed' => 20],
            'group' => ['themed' => 50, 'mixed' => 50],
            default => ['mixed' => 80, 'themed' => 20],
        });
        $themeCategory = $this->rand->pick(array_keys($this->byCategory));
        $themeItem = $this->weightedItem(array_keys($this->catalog), 'w_supply');

        $needsId = $unknown && $this->rand->chance(0.5);
        $driverId = $freight ? $this->rand->pick($this->inboundDrivers) : null;
        $personId = $donor['id'] ?? $this->unknownDonorId;

        $donation = Transaction::create([
            'type' => 'donation',
            'category' => $category,
            'person_id' => $personId,
            'person_id_user' => $this->adminId,
            'contact_person_id' => $donor['contact_id'] ?? null,
            'status_id' => $this->status($category === 'donation' ? Transaction::STATUS_RECEIVED : Transaction::STATUS_LOGGED),
            'order_date' => $this->today,
            'container_count' => $containerCount,
            'container_types' => array_values($types),
            'container_type_counts' => $counts,
            'quick_sort_candidate' => $theme === 'single',
            'donor_identification_pending' => $needsId,
            'arrival_method' => $arrival,
            'driver_id' => $driverId,
            'manifest' => match ($category) {
                'donation' => $containerCount.' '.(in_array('pallet', $types, true) ? 'pallet' : 'container').($containerCount === 1 ? '' : 's').' — '.($theme === 'single' ? 'one product' : ($theme === 'themed' ? 'mostly one category' : 'mixed goods')),
                'equipment' => 'Equipment — '.$this->rand->pick(['pallet jack', 'shelving units', 'forklift battery', 'hand trucks']),
                'supplies' => 'Warehouse supplies — '.$this->rand->pick(['shrink wrap', 'pallet labels', 'box cutters', 'work gloves']),
                default => 'Other: '.$this->rand->pick(['vehicle for the fleet', 'cash donation receipt', 'volunteer meal delivery']),
            },
            'manifest_weight_lbs' => $category === 'donation' ? $containerCount * $this->rand->int(60, 1100) : null,
            'source_address' => $donor['address'] ?? null,
            'source_city' => $donor['city'] ?? null,
            'source_state' => $donor ? 'NC' : null,
            'source_zip' => $donor['zip'] ?? null,
            'comments' => $this->rand->chance(0.08) ? $this->rand->pick(['Driver needed the dock cleared', 'Some wet boxes on top pallet', 'Receipt requested for taxes', 'Called ahead — expected']) : null,
            'source_system' => self::SOURCE,
            'source_ref' => 'donation-'.(++$this->counter),
        ]);

        $this->matchOffer($donor, $donation);

        if ($category !== 'donation') {
            return;
        }

        $dock = $this->locations['DOCK-IN'];
        $palletIds = [];
        foreach ($counts as $type => $n) {
            for ($k = 0; $k < $n; $k++) {
                $pallet = Pallet::create([
                    'kind' => 'R', 'status' => 'received', 'container_type' => $type,
                    'donor_person_id' => $personId, 'orderdonation_id' => $donation->id,
                    'location_id' => $dock->id, 'datepacked' => $this->today,
                ]);
                $pallet->statuses()->create(['status' => 'received', 'location_id' => $dock->id]);
                $palletIds[] = [$pallet->id, $type];
            }
        }

        $lag = $this->lag([0 => 30, 1 => 30, 2 => 20, 3 => 10, 5 => 6, 9 => 4]);
        foreach ($palletIds as $k => [$palletId, $type]) {
            $this->schedule($lag + intdiv($k, 3) + ($this->rand->chance(0.15) ? 1 : 0), 'sort', [
                'pallet' => $palletId, 'type' => $type, 'theme' => $theme, 'category' => $themeCategory, 'item' => $themeItem,
                'quality' => $donor['quality'] ?? 0.8, 'new_share' => $donor['new_share'] ?? 0.2,
            ]);
        }
    }

    private function weightedItem(array $itemIds, string $weightKey): int
    {
        $weights = [];
        foreach ($itemIds as $id) {
            $weights[$id] = $this->catalog[$id][$weightKey];
        }

        return (int) $this->rand->weighted($weights);
    }

    /** About a third of business/group donations were phoned in first; the offer gets matched to the arrival. */
    private function matchOffer(?array $donor, Transaction $donation): void
    {
        if (! $donor || $donor['kind'] === 'individual' || ! $this->rand->chance(0.33)) {
            return;
        }

        $offer = $this->makeOffer($donor, $this->now->copy()->subDays($this->rand->int(1, 6))->setTime($this->rand->int(9, 16), $this->rand->int(0, 59)));
        $this->asOf($this->now->copy()->subMinutes(5), fn () => $offer->transitionTo(DonationOffer::STATUS_RECEIVED, $this->adminId, ['donation_id' => $donation->id], null, 'Matched at the dock'));
    }

    /** An offer taken by phone and accepted (pending), as of $at. */
    private function makeOffer(array $donor, Carbon $at, bool $accept = true): DonationOffer
    {
        return $this->asOf($at, function () use ($donor, $accept) {
            $offer = DonationOffer::create([
                'person_id' => $donor['id'], 'contact_person_id' => $donor['contact_id'],
                'description' => $this->rand->pick(['Pallets of mixed goods', 'Truckload of water and canned food', 'Cleaning supplies and hygiene kits', 'Overstock bedding', 'Baby supplies']),
                'status' => DonationOffer::STATUS_OFFERED, 'entered_by_person_id' => $this->adminId,
            ]);
            $offer->statusLogs()->create(['from_status' => null, 'to_status' => DonationOffer::STATUS_OFFERED, 'changed_by_person_id' => $this->adminId, 'contact_method' => 'phone']);
            if ($accept) {
                $eta = Carbon::now($this->tz)->addDays($this->rand->int(1, 6));
                $offer->transitionTo(DonationOffer::STATUS_PENDING, $this->adminId, ['eta_start' => $eta->toDateString(), 'eta_end' => $eta->toDateString()], 'phone', 'Accepted; driver will call ahead');
            }

            return $offer;
        });
    }

    /** An offer that never turned into a donation: refused, diverted elsewhere, or cancelled by the donor. */
    private function standaloneOffer(): void
    {
        $donor = $this->pickDonor();
        $outcome = $this->rand->weighted(['refused' => 40, 'diverted' => 25, 'cancelled' => 35]);
        $offer = $this->makeOffer($donor, $this->now->copy()->addMinutes($this->rand->int(10, 300)), accept: $outcome === 'cancelled');

        $this->asOf($this->now->copy()->addHours(6), function () use ($offer, $outcome) {
            $offer->transitionTo(
                $outcome,
                $this->adminId,
                match ($outcome) {
                    'refused' => ['refused_reason' => $this->rand->pick(['Perishable, no cold storage', 'Over capacity this week', 'Items not on our list'])],
                    'diverted' => ['diverted_to' => $this->rand->pick(['Local food bank', 'Salvation Army', 'Partner pantry nearby'])],
                    default => ['cancelled_reason' => $this->rand->pick(['Donor changed plans', 'Truck unavailable', 'Donor could not be reached'])],
                },
                'phone'
            );
        });
    }

    private function sortPallet(array $e): void
    {
        $pallet = Pallet::find($e['pallet']);
        $sortLoc = $this->locations[$this->rand->chance(0.5) ? 'SORT-1' : 'SORT-2'];

        $pallet->transitionTo('sorting', $sortLoc->id);
        $this->tick(20, 90);

        $big = $e['type'] === 'pallet';
        $pool = $e['theme'] === 'themed' ? ($this->byCategory[$e['category']] ?? array_keys($this->catalog)) : array_keys($this->catalog);

        if ($e['theme'] === 'single') {
            $lines = [[$e['item'], (int) round($this->rand->logInt(80, 600) * $this->supplyScale * ($big ? 1 : 0.3))]];
        } else {
            $n = $big ? ($e['theme'] === 'themed' ? $this->rand->int(4, 9) : $this->rand->int(6, 14)) : $this->rand->int(2, 6);
            $chosen = [];
            for ($a = 0; $a < $n * 3 && count($chosen) < $n; $a++) {
                $chosen[$this->weightedItem($pool, 'w_supply')] = true;
            }
            $lines = [];
            foreach (array_keys($chosen) as $itemId) {
                $lines[] = [$itemId, (int) max(1, round(($big ? $this->rand->logInt(12, 240) : $this->rand->logInt(3, 60)) * $this->supplyScale))];
            }
        }

        foreach ($lines as [$itemId, $qty]) {
            $this->tick(5, 25);
            $this->addLedger($pallet, $itemId, $qty, $e);
        }

        $this->tick(10, 60);
        $pallet->transitionTo('empty', null, $this->rand->chance(0.04) ? 'Sorter: set aside - possible damage' : 'Sorter: pallet looks OK');
        // Supervisor QC usually catches up within a day or two; recent pallets stay "pending".
        if ($this->dayIndex < $this->totalDays - 2 && $this->rand->chance(0.9)) {
            $pallet->forceFill(['condition' => $this->rand->chance(0.03) ? 'condemned' : 'good'])->save();
        }
    }

    /** One sorted line: mostly usable, with some loss rows — what donor-quality reporting is built from. */
    private function addLedger(Pallet $pallet, int $itemId, int $qty, array $e): void
    {
        $donationId = $pallet->orderdonation_id;
        $row = fn (int $q, string $disposition, ?string $condition = null) => ItemLedger::create([
            'orderdonation_id' => $donationId, 'item_id' => $itemId, 'pallet_id' => $pallet->id,
            'qty_added' => $q, 'disposition' => $disposition, 'item_condition' => $condition,
            'person_id_user' => $this->adminId,
        ]);

        // A wholly non-usable line is rare; otherwise a usable row with an optional loss row beside it.
        if ($this->rand->chance(0.02)) {
            $row($qty, $this->rand->weighted(['outdated' => 50, 'trashed' => 30, 'diverted' => 20]));

            return;
        }

        $lost = $this->rand->chance((1 - $e['quality']) * 1.5) ? max(1, (int) round($qty * (0.05 + $this->rand->float() * 0.25))) : 0;
        $usable = max(0, $qty - $lost);

        if ($usable > 0) {
            $row($usable, 'usable', $this->rand->chance($e['new_share']) ? 'new' : 'used');
            $this->stock[$itemId] += $usable;
            $this->pool[$itemId] = ($this->pool[$itemId] ?? 0) + $usable;
        }
        if ($lost > 0) {
            $this->tick(2, 8);
            $row($lost, $this->rand->weighted(['outdated' => 40, 'trashed' => 35, 'diverted' => 25]));
        }
    }

    /**
     * The daily forklift run: what sorters have produced goes from the sorting area onto warehouse pallets, filled in
     * sequence so several items share a pallet (like the real "fill it as it leaves sorting" flow), each sealed at a rack.
     * Some stock is deliberately left behind — a skipped item now and then, and a remainder on many — so the sorting-area
     * report and the "unassigned" figures have something real to show.
     */
    private function putAwayDay(): void
    {
        $items = array_keys(array_filter($this->pool, fn ($qty) => $qty > 0));
        $this->rand->shuffle($items);

        $capacity = 0;
        $pallet = null;
        foreach ($items as $itemId) {
            if ($this->rand->chance(0.06)) {
                continue; // forgotten in the corner until another day
            }
            $qty = $this->pool[$itemId];
            if ($this->rand->chance(0.25)) {
                $qty -= (int) floor($qty * (0.05 + $this->rand->float() * 0.25)); // a few cases never leave
            }
            while ($qty > 0) {
                if ($pallet === null || $capacity <= 0) {
                    $pallet = $this->newWarehousePallet();
                    $capacity = $this->rand->int(60, 240);
                }
                $put = min($qty, $capacity);
                PalletStockAssignment::create(['pallet_id' => $pallet->id, 'item_id' => $itemId, 'qty' => $put, 'person_id_user' => $this->adminId, 'notes' => 'Filled leaving sorting']);
                $this->pallets[$pallet->id]['items'][$itemId] = ($this->pallets[$pallet->id]['items'][$itemId] ?? 0) + $put;
                $this->palletsByItem[$itemId][$pallet->id] = true;
                $this->pool[$itemId] -= $put;
                $qty -= $put;
                $capacity -= $put;
                $this->tick(5, 40);
            }
        }
    }

    private function newWarehousePallet(): Pallet
    {
        $loc = $this->rand->pick($this->racks);
        $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'container_type' => 'pallet', 'location_id' => $loc->id, 'datepacked' => $this->today]);
        $pallet->statuses()->create(['status' => 'sealed', 'location_id' => $loc->id, 'notes' => 'Filled and sealed']);
        $this->pallets[$pallet->id] = ['tag' => $pallet->tag, 'status' => 'sealed', 'location' => $loc->code, 'sequence' => $loc->PullSequence, 'items' => []];

        if ($this->rand->chance(0.18)) {
            $this->schedule($this->rand->int(7, 90), 'move', ['pallet' => $pallet->id]);
        }

        return $pallet;
    }

    /**
     * Take $qty of an item the way the real pick plan does (PickPlanner): whole sealed pallets, open
     * pallets, then the oldest sealed one opened, then the sorting area as a last resort. Status changes happen as a picker would make them.
     *
     * @return array<int, array{0: ?int, 1: int}> [pallet id (null = sorting area / over-pick), qty] chunks summing to $qty
     */
    private function pickChunks(int $itemId, int $qty): array
    {
        $held = [];
        foreach (array_keys($this->palletsByItem[$itemId] ?? []) as $palletId) {
            $p = $this->pallets[$palletId];
            if (($p['items'][$itemId] ?? 0) > 0 && in_array($p['status'], ['sealed', 'open'], true)) {
                $held[] = ['pallet_id' => $palletId, 'tag' => $p['tag'], 'status' => $p['status'], 'location' => $p['location'],
                    'sequence' => $p['sequence'], 'item_id' => $itemId, 'item' => '', 'qty' => $p['items'][$itemId]];
            }
        }
        $plan = PickPlanner::planFor(['pool' => [], 'pallets' => $held], $qty);

        $chunks = [];
        foreach ($plan['steps'] as $step) {
            $palletId = $step['pallet_id'];
            $record = Pallet::find($palletId);
            if ($this->pallets[$palletId]['status'] === 'sealed') {
                $record->transitionTo('open', null, $step['whole'] ? 'Taken whole for an order' : 'Opened for picking');
                $this->pallets[$palletId]['status'] = 'open';
            }
            $this->pallets[$palletId]['items'][$itemId] -= $step['qty'];
            if (array_sum($this->pallets[$palletId]['items']) <= 0) {
                $record->transitionTo('empty', null, 'Picked clean');
                $this->pallets[$palletId]['status'] = 'empty';
            }
            $chunks[] = [$palletId, $step['qty']];
        }
        // Pallets couldn't cover it: look for others in the sorting area, as a picker would.
        $short = $plan['short'];
        $fromPool = min($short, max(0, $this->pool[$itemId] ?? 0));
        if ($fromPool > 0) {
            $this->pool[$itemId] -= $fromPool;
            $chunks[] = [null, $fromPool];
            $short -= $fromPool;
        }
        if ($short > 0) {
            // An injected over-pick: more keyed than the database says exists.
            $this->pool[$itemId] = ($this->pool[$itemId] ?? 0) - $short;
            $chunks[] = [null, $short];
        }

        return $chunks;
    }

    private function movePallet(int $palletId): void
    {
        $pallet = Pallet::find($palletId);
        if (! $pallet || $pallet->status === 'empty') {
            return;
        }
        $target = $this->rand->pick($this->racks);
        if ($target->id !== $pallet->location_id) {
            $pallet->transitionTo($pallet->status, $target->id, 'Relocated during re-slotting');
            $this->pallets[$palletId]['location'] = $target->code;
            $this->pallets[$palletId]['sequence'] = $target->PullSequence;
        }
    }

    // ------------------------------------------------------------------
    // Orders: create, fill, ship, deliver, review
    // ------------------------------------------------------------------

    private function createOrder(): void
    {
        $this->tick(30, 180);

        $weights = [];
        foreach ($this->partners as $i => $p) {
            $weights[$i] = $p['weight'];
        }
        $partner = $this->partners[$this->rand->weighted($weights)];

        $order = Transaction::create([
            'type' => 'order', 'person_id' => $partner['id'], 'person_id_user' => $this->adminId,
            'status_id' => $this->status(Transaction::STATUS_NEW_ORDER), 'order_date' => $this->today,
            'source_system' => self::SOURCE, 'source_ref' => 'order-'.(++$this->counter),
        ]);

        $lineCount = min(12, 1 + $this->rand->poisson(2.8));
        $chosen = [];
        for ($a = 0; $a < $lineCount * 3 && count($chosen) < $lineCount; $a++) {
            $chosen[$this->weightedItem(array_keys($this->catalog), 'w_demand')] = true;
        }

        $lines = [];
        foreach (array_keys($chosen) as $itemId) {
            $this->tick(5, 25);
            $qty = max(1, (int) round($this->rand->logInt(4, 120) * $partner['size']));
            $line = $order->orderLines()->create([
                'itemtype_id' => $this->catalog[$itemId]['itemtype_id'], 'qty_requested' => $qty,
                'need_level' => (fn ($level) => $level === 'none' ? null : $level)($this->rand->weighted(['none' => 50, 'critical' => 10, 'moderate' => 25, 'low' => 15])),
            ]);
            $lines[] = ['id' => $line->id, 'item_id' => $itemId, 'requested' => $qty];
            $this->requestedUnits += $qty;
        }

        $delivery = $this->rand->chance(0.65);
        $this->tick(20, 120);
        $order->fill([
            'status_id' => $this->status(Transaction::STATUS_READY_TO_FILL),
            'fulfillment_method' => $delivery ? 'delivery' : 'pickup',
            'needed_by_date' => Carbon::now($this->tz)->addDays($this->rand->int(3, 14))->toDateString(),
            'delivery_days' => $delivery ? ($this->rand->chance(0.5) ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : $this->rand->pickMany(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $this->rand->int(1, 3))) : null,
            'preferred_time' => $delivery ? $this->rand->pick(['Morning', 'Afternoon', 'Any time', 'Before noon']) : null,
            'contact_name' => $partner['contact'], 'contact_phone' => $partner['phone'],
            'special_instructions' => $delivery && $this->rand->chance(0.2) ? $this->rand->pick(['Use the side gate', 'Call 30 minutes before arrival', 'Loading dock is behind the fellowship hall', 'Forklift available on site']) : null,
            'other_needs' => $this->rand->chance(0.1) ? $this->rand->pick(['Any extra tarps', 'Diapers if available', 'Work gloves', 'Cots']) : null,
        ])->save();

        $this->orders[$order->id] = ['partner' => $partner['id'], 'lines' => $lines, 'delivery' => $delivery, 'picked' => 0, 'pallets' => [], 'rejected' => false, 'name' => $partner['contact']];
        $this->schedule($this->lag([0 => 35, 1 => 30, 2 => 20, 3 => 8, 5 => 5, 8 => 2]), 'fill', ['order' => $order->id]);
    }

    private function fillOrder(int $orderId): void
    {
        $order = Transaction::find($orderId);
        $plan = &$this->orders[$orderId];

        // A manager sometimes caps a short line to what can be spared before picking starts.
        foreach ($plan['lines'] as $i => $line) {
            $available = max(0, $this->stock[$line['item_id']]);
            if ($available < $line['requested'] && $this->rand->chance(0.55)) {
                $approved = (int) floor($available * (0.4 + $this->rand->float() * 0.6));
                OrderLine::whereKey($line['id'])->update(['qty_approved' => $approved, 'approved_by_person_id' => $this->adminId, 'approved_at' => $this->now]);
                $plan['lines'][$i]['approved'] = $approved;
            }
        }

        $this->tick(60, 600);
        $order->update(['status_id' => $this->status(Transaction::STATUS_FILLING)]);

        foreach ($plan['lines'] as $line) {
            $toPick = $line['approved'] ?? $line['requested'];
            if ($toPick <= 0) {
                continue; // approved at 0: nothing to pick, no fill record needed
            }

            $this->tick(20, 120);
            $itemId = $line['item_id'];
            $available = max(0, $this->stock[$itemId]);
            $take = min($toPick, $available);

            // Injected error: a picker keys more than the shelf actually held.
            if ($this->errorDays && $this->dayIndex >= $this->errorDays[0] && $available >= 1 && $available <= 120) {
                $take = $available + $this->rand->int(5, 30);
                $this->injected[] = [
                    'date' => $this->today, 'order_id' => $orderId, 'item_id' => $itemId, 'item' => $this->catalog[$itemId]['name'],
                    'on_hand_before' => $available, 'picked' => $take, 'on_hand_after' => $available - $take,
                ];
                array_shift($this->errorDays);
            }

            // Draw from warehouse pallets first (their status changes happen as the picker works), then record
            // each chunk against the pallet it came from; occasionally a picker records one chunk in two trips.
            foreach ($this->pickChunks($itemId, $take) as [$palletId, $chunk]) {
                $parts = ($chunk >= 4 && $this->rand->chance(0.1)) ? [(int) round($chunk * 0.6), $chunk - (int) round($chunk * 0.6)] : [$chunk];
                foreach ($parts as $part) {
                    ItemLedger::create([
                        'orderdonation_id' => $orderId, 'item_id' => $itemId, 'order_line_id' => $line['id'], 'stock_pallet_id' => $palletId,
                        'qty_subtracted' => $part, 'disposition' => 'usable', 'person_id_user' => $this->adminId,
                    ]);
                    $this->tick(5, 40);
                }
            }

            $this->stock[$itemId] -= $take;
            $plan['picked'] += $take;
            $this->filledUnits += min($take, $line['requested']);
        }

        $this->schedule($this->lag([0 => 75, 1 => 17, 2 => 8]), 'complete_fill', ['order' => $orderId]);
    }

    private function completeFilling(int $orderId): void
    {
        $order = Transaction::find($orderId);
        $plan = &$this->orders[$orderId];

        if ($plan['picked'] === 0) {
            $order->update(['status_id' => $this->status(Transaction::STATUS_CLOSED_UNFULFILLED)]);

            return;
        }

        $palletCount = max(1, min(4, (int) ceil($plan['picked'] / $this->rand->int(120, 400))));
        $order->update(['status_id' => $this->status(Transaction::STATUS_FILLED), 'pallet_count' => $palletCount]);

        $dockOut = $this->locations['DOCK-OUT'];
        for ($k = 0; $k < min($palletCount, 3); $k++) {
            $pallet = Pallet::create(['kind' => 'S', 'status' => 'building', 'container_type' => 'pallet', 'destination_person_id' => $plan['partner'], 'location_id' => $dockOut->id, 'datepacked' => $this->today]);
            $pallet->statuses()->create(['status' => 'building', 'location_id' => $dockOut->id]);
            $this->tick(5, 30);
            $pallet->transitionTo('ready');
            $plan['pallets'][] = $pallet->id;
        }

        $this->schedule($this->lag([0 => 40, 1 => 30, 2 => 20, 3 => 10]), 'assign', ['order' => $orderId]);
    }

    private function assignDriver(int $orderId): void
    {
        $plan = $this->orders[$orderId];
        if ($plan['delivery']) {
            $driverId = $this->rand->pick($this->outboundDrivers);
        } else {
            // Partner collects it themselves: a driver record in the partner's own name.
            $driverId = $this->pickupDrivers[$plan['partner']] ??= Driver::create(['name' => $plan['name'], 'phone' => $this->partnerPhone($plan['partner']), 'carrier' => 'Partner pickup'])->id;
        }

        Transaction::find($orderId)->update([
            'driver_id' => $driverId,
            'status_id' => $this->status(Transaction::STATUS_READY_TO_SHIP),
            'bol_number' => 'BOL-'.str_pad((string) $orderId, 6, '0', STR_PAD_LEFT),
        ]);

        $this->schedule($this->lag([0 => 60, 1 => 40]), 'ship', ['order' => $orderId]);
    }

    private function partnerPhone(int $personId): ?string
    {
        foreach ($this->partners as $p) {
            if ($p['id'] === $personId) {
                return $p['phone'];
            }
        }

        return null;
    }

    private function markShipped(int $orderId): void
    {
        Transaction::find($orderId)->update(['status_id' => $this->status(Transaction::STATUS_SHIPPED)]);
        foreach ($this->orders[$orderId]['pallets'] as $palletId) {
            Pallet::find($palletId)->transitionTo('shipped');
        }

        $this->schedule($this->lag([1 => 50, 2 => 30, 3 => 20]), 'deliver', ['order' => $orderId]);
    }

    private function storeBol(int $orderId): string
    {
        $path = "signed-bols/simulated-{$orderId}-".$this->rand->int(1000, 9999).'.png';
        Storage::disk('local')->put($path, base64_decode(self::BOL_PNG));

        return $path;
    }

    private function deliver(int $orderId): void
    {
        Transaction::find($orderId)->update(['status_id' => $this->status(Transaction::STATUS_DELIVERED), 'signed_bol_path' => $this->storeBol($orderId)]);

        // A few uploads sit unreviewed so the manager's review queue is never empty.
        if (! $this->rand->chance(0.03)) {
            $this->schedule($this->lag([0 => 40, 1 => 30, 2 => 15, 4 => 10, 6 => 5]), 'bol_review', ['order' => $orderId]);
        }
    }

    private function reviewBol(int $orderId): void
    {
        $order = Transaction::find($orderId);
        $plan = &$this->orders[$orderId];

        if (! $plan['rejected'] && $this->rand->chance(0.08)) {
            $plan['rejected'] = true;
            if ($order->signed_bol_path) {
                Storage::disk('local')->delete($order->signed_bol_path);
            }
            $order->update([
                'status_id' => $this->status(Transaction::STATUS_DELIVERED_AWAITING_BOL), 'signed_bol_path' => null,
                'bol_rejection_reason' => $this->rand->pick(['Photo is blurry', 'Signature is missing', 'That is the wrong document']),
                'bol_reviewed_by_person_id' => $this->adminId,
            ]);
            $this->schedule($this->lag([1 => 50, 2 => 30, 4 => 20]), 'bol_reupload', ['order' => $orderId]);

            return;
        }

        $order->update(['status_id' => $this->status(Transaction::STATUS_COMPLETED), 'bol_rejection_reason' => null, 'bol_reviewed_by_person_id' => $this->adminId]);
    }

    private function reuploadBol(int $orderId): void
    {
        Transaction::find($orderId)->update(['status_id' => $this->status(Transaction::STATUS_DELIVERED), 'signed_bol_path' => $this->storeBol($orderId), 'bol_rejection_reason' => null]);
        $this->schedule($this->lag([0 => 50, 1 => 35, 3 => 15]), 'bol_review', ['order' => $orderId]);
    }

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------

    private function summary(int $seed, Carbon $start, int $days): array
    {
        $statusCounts = fn (string $type) => DB::table('orderdonations')
            ->join('statuses', 'statuses.id', '=', 'orderdonations.status_id')
            ->where('orderdonations.type', $type)
            ->groupBy('statuses.name')
            ->selectRaw('statuses.name as name, count(*) as n')
            ->pluck('n', 'name')
            ->map(fn ($n) => (int) $n)
            ->all();

        $negative = [];
        foreach ($this->stock as $itemId => $qty) {
            if ($qty < 0) {
                $negative[] = ['item_id' => $itemId, 'item' => $this->catalog[$itemId]['name'], 'on_hand' => $qty];
            }
        }

        return [
            'seed' => $seed,
            'from' => $start->toDateString(),
            'to' => $start->copy()->addDays($days - 1)->toDateString(),
            'days' => $days,
            'counts' => [
                'donations' => DB::table('orderdonations')->where('type', 'donation')->count(),
                'orders' => DB::table('orderdonations')->where('type', 'order')->count(),
                'order_lines' => DB::table('orderlines')->count(),
                'ledger_rows' => DB::table('item_ledgers')->count(),
                'pallets' => DB::table('pallets')->count(),
                'warehouse_pallets' => DB::table('pallets')->where('kind', 'W')->count(),
                'pallet_stock_assignments' => DB::table('pallet_stock_assignments')->count(),
                'stock_still_in_sorting_area' => (int) array_sum(array_filter($this->pool, fn ($q) => $q > 0)),
                'pallet_history_rows' => DB::table('palletstatus')->count(),
                'people' => DB::table('people')->where('source_system', self::SOURCE)->count(),
                'drivers' => DB::table('drivers')->count(),
                'donation_offers' => DB::table('donation_offers')->count(),
                'locations' => DB::table('locations')->count(),
                'item_types_in_play' => count($this->catalog),
            ],
            'donation_statuses' => $statusCounts('donation'),
            'order_statuses' => $statusCounts('order'),
            'fill_rate_percent' => $this->requestedUnits > 0 ? round(100 * $this->filledUnits / $this->requestedUnits, 1) : null,
            'injected_errors' => $this->injected,
            'items_negative_now' => $negative,
        ];
    }
}
