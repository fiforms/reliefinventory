<?php

use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\OrderLine;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\Transaction;
use App\Models\UseModel;
use App\Models\Warehouse;

function stockItem(int $onHand): Item
{
    $unitId = DB::table('units')->where('name', 'Case')->value('id') ?? DB::table('units')->insertGetId(['name' => 'Case', 'abbreviation' => 'cs']);
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Box')->value('id') ?? DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);
    $type = ItemType::create(['name' => 'Bottled Water', 'unit_id' => $unitId, 'category_id' => Category::firstOrCreate(['name' => 'Beverages'])->id]);
    $item = Item::create(['itemtype_id' => $type->id, 'packagetypes_id' => $packagetypeId, 'description' => 'Case of water']);
    $donation = Transaction::create(['type' => 'donation', 'person_id' => Person::create(['first_name' => 'Dee', 'last_name' => 'Donor'])->id, 'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_SORTING), 'order_date' => now()->toDateString()]);
    ItemLedger::create(['orderdonation_id' => $donation->id, 'item_id' => $item->id, 'qty_added' => $onHand, 'disposition' => 'usable']);

    return $item;
}

function rack(string $code): Location
{
    $wh = Warehouse::first() ?? Warehouse::create(['name' => 'WH', 'address' => '1 Main St', 'city' => 'Town', 'state' => 'CA', 'zip' => '90000']);
    $use = UseModel::first() ?? UseModel::create(['use' => 'Storage']);

    return Location::forceCreate(['warehouse_id' => $wh->id, 'use_id' => $use->id, 'code' => $code, 'status' => 'active']);
}

beforeEach(fn () => $this->actingAs(userWithPermissions('manage-pallets', 'manage-orders', 'view-reports', 'general-access')));

test('filling a new pallet records it sealed at the location and drains the sorting-area pool', function () {
    $item = stockItem(200);
    $a1 = rack('A-1');

    $this->getJson('/json/pallet-stock/staging')->assertOk()->assertJsonPath('records.0.qty', 200);

    $res = $this->postJson('/json/pallet-stock/fill', ['location_id' => $a1->id, 'lines' => [['item_id' => $item->id, 'qty' => 96]]])
        ->assertCreated()->assertJsonPath('record.status', 'sealed')->assertJsonPath('record.location.code', 'A-1')
        ->assertJsonPath('record.contents.0.qty', 96);

    $this->getJson('/json/pallet-stock/staging')->assertJsonPath('records.0.qty', 104);
    expect(Pallet::find($res->json('record.id'))->kind)->toBe('W');
});

test('a pallet cannot hold more than is unassigned', function () {
    $item = stockItem(50);
    $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 30]]])->assertCreated();
    $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 30]]])
        ->assertStatus(422)->assertJsonPath('errors.lines.0', fn ($m) => str_contains($m, 'Only 20'));
});

test('a sealed pallet must be opened before it can be picked from; then it draws down and empties', function () {
    $item = stockItem(100);
    $pallet = Pallet::find($this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 60]]])->json('record.id'));
    $order = Transaction::create([
        'type' => 'order', 'person_id' => Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera'])->id,
        'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
        'order_date' => now()->toDateString(),
    ]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $item->itemtype_id, 'qty_requested' => 60]);

    $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 25, 'pallet_tag' => $pallet->tag])
        ->assertStatus(422)->assertJsonPath('errors.pallet_tag.0', fn ($m) => str_contains($m, 'open it first'));
    $plan = $this->getJson("/json/order-filling/{$order->id}/plan")->assertOk()->json('lines.'.$line->id);
    expect(collect($plan['steps'])->pluck('qty', 'kind')->all())->toBe(['pool' => 40, 'pallet' => 20]); // pool of 40 first, then 20 of the sealed 60 (opened first)
    expect(collect($plan['steps'])->firstWhere('kind', 'pallet')['needs_open'])->toBeTrue();

    $this->postJson("/json/order-filling/pallets/{$pallet->id}/open")->assertOk()->assertJsonPath('record.status', 'open');
    $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 25, 'pallet_tag' => $pallet->tag])->assertCreated();
    $this->getJson("/json/pallets/{$pallet->id}/contents")->assertJsonPath('record.contents.0.qty', 35);

    $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 35, 'pallet_tag' => $pallet->tag])->assertCreated();
    expect($pallet->fresh()->status)->toBe('empty');
});

test('the inventory report breaks an item type down by location with an unassigned remainder', function () {
    $item = stockItem(200);
    $a1 = rack('A-1');
    $b2 = rack('B-2');
    $this->postJson('/json/pallet-stock/fill', ['location_id' => $b2->id, 'lines' => [['item_id' => $item->id, 'qty' => 40]]])->assertCreated();
    $this->postJson('/json/pallet-stock/fill', ['location_id' => $a1->id, 'lines' => [['item_id' => $item->id, 'qty' => 96]]])->assertCreated();

    $rec = collect($this->getJson('/json/reports/inventory')->assertOk()->json('records'))->firstWhere('id', $item->itemtype_id);
    expect(collect($rec['locations'])->pluck('code')->all())->toBe(['A-1', 'B-2'])
        ->and($rec['locations'][0]['qty'])->toBe(96)
        ->and($rec['unassigned'])->toBe(64);
});

test('a pick with no pallet named splits across the sorting-area pool, then open pallets oldest first', function () {
    $item = stockItem(100);
    $first = Pallet::find($this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 40]]])->json('record.id'));
    $second = Pallet::find($this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 50]]])->json('record.id'));
    // 100 on hand: 40 + 50 on pallets, 10 still in the sorting area
    $order = Transaction::create([
        'type' => 'order', 'person_id' => Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera'])->id,
        'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
        'order_date' => now()->toDateString(),
    ]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $item->itemtype_id, 'qty_requested' => 60]);
    $fillUrl = "/json/order-filling/{$order->id}/lines/{$line->id}/fills";

    // Within the pool: no pallet involved, nothing needs opening.
    $this->postJson($fillUrl, ['item_id' => $item->id, 'qty' => 10])->assertCreated();

    // Pool is now 0 and both pallets are sealed: the picker is told to open one — nothing is written.
    $blocked = $this->postJson($fillUrl, ['item_id' => $item->id, 'qty' => 5])->assertStatus(422);
    expect($blocked->json('sealed_pallets'))->toHaveCount(2)->and($blocked->json('message'))->toContain('Open a sealed pallet');
    expect(ItemLedger::where('order_line_id', $line->id)->count())->toBe(1);

    // Open the older pallet (40) and pick 55: 40 from it, then the next-oldest is still sealed -> blocked.
    $this->postJson("/json/order-filling/pallets/{$first->id}/open")->assertOk();
    $this->postJson($fillUrl, ['item_id' => $item->id, 'qty' => 55])->assertStatus(422);
    $this->postJson("/json/order-filling/pallets/{$second->id}/open")->assertOk();

    $res = $this->postJson($fillUrl, ['item_id' => $item->id, 'qty' => 55])->assertCreated();
    expect(collect($res->json('records'))->pluck('qty_subtracted', 'stock_pallet_id')->all())->toBe([$first->id => 40, $second->id => 15]);
    expect($first->fresh()->status)->toBe('empty')->and($second->fresh()->status)->toBe('open');
    $this->getJson("/json/pallets/{$second->id}/contents")->assertJsonPath('record.contents.0.qty', 35);
});

test('with the pool and one open pallet short, pick the pool first then split to the open pallet', function () {
    $item = stockItem(20);
    $pallet = Pallet::find($this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 17]]])->json('record.id'));
    $this->postJson("/json/order-filling/pallets/{$pallet->id}/open")->assertOk(); // pool = 3
    $order = Transaction::create([
        'type' => 'order', 'person_id' => Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera'])->id,
        'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
        'order_date' => now()->toDateString(),
    ]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $item->itemtype_id, 'qty_requested' => 7]);

    $res = $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 7])->assertCreated();
    expect(collect($res->json('records'))->pluck('qty_subtracted', 'stock_pallet_id')->all())->toBe(['' => 3, $pallet->id => 4]);
});

test('a sealed pallet can leave whole without being opened first, but only whole', function () {
    $item = stockItem(80);
    $pallet = Pallet::find($this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 80]]])->json('record.id'));
    $order = Transaction::create([
        'type' => 'order', 'person_id' => Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera'])->id,
        'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
        'order_date' => now()->toDateString(),
    ]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $item->itemtype_id, 'qty_requested' => 80]);
    $url = "/json/order-filling/{$order->id}/lines/{$line->id}/fills";

    $this->postJson($url, ['item_id' => $item->id, 'qty' => 79, 'pallet_tag' => $pallet->tag])->assertStatus(422);
    $this->postJson($url, ['item_id' => $item->id, 'qty' => 80, 'pallet_tag' => $pallet->tag])->assertCreated();
    expect($pallet->fresh()->status)->toBe('empty');
    expect($pallet->statuses()->pluck('status')->all())->toBe(['sealed', 'open', 'empty']);
});

test('saving the pick path renumbers locations in the given order and keeps unlisted ones after', function () {
    $this->actingAs(userWithPermissions('manage-locations', 'general-access'));
    $a = rack('A-1');
    $b = rack('B-1');
    $c = rack('C-1');

    $this->putJson('/json/locations/order', ['ids' => [$c->id, $a->id]])->assertOk();

    expect(Location::orderBy('PullSequence')->pluck('code')->all())->toBe(['C-1', 'A-1', 'B-1'])
        ->and($c->fresh()->PullSequence)->toBe(10)->and($b->fresh()->PullSequence)->toBe(30);
    $this->getJson('/json/locations')->assertJsonPath('records.0.code', 'C-1');
});

test('the sorting-area report shows sorted vs. put on pallets and what is still waiting', function () {
    $this->actingAs(userWithPermissions('manage-pallets', 'view-reports', 'general-access'));
    $item = stockItem(120); // sorted today
    $this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 80]]])->assertCreated();

    $today = now()->toDateString();
    $report = $this->getJson("/json/reports/sorting-area?from={$today}&to={$today}")->assertOk();
    $report->assertJsonPath('items.0.sorted', 120)->assertJsonPath('items.0.moved', 80)->assertJsonPath('items.0.in_area', 40)
        ->assertJsonPath('items.0.waiting_since', $today)->assertJsonPath('totals.in_area', 40);

    // Sorted yesterday's range shows nothing sorted/moved, but the 40 are still waiting.
    $yesterday = now()->subDay()->toDateString();
    $this->getJson("/json/reports/sorting-area?from={$yesterday}&to={$yesterday}")
        ->assertJsonPath('items.0.sorted', 0)->assertJsonPath('items.0.in_area', 40);

    $this->get('/report/sorting-area.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

test('filling a pallet records its expiration date, and the sorting-area list suggests one from dated lines', function () {
    $item = stockItem(50);
    ItemLedger::where('item_id', $item->id)->update(['expires_on' => '2027-02-02']);

    $this->getJson('/json/pallet-stock/staging')->assertOk()->assertJsonPath('records.0.expires_on', '2027-02-02');

    $id = $this->postJson('/json/pallet-stock/fill', ['expires_on' => '2027-02-02', 'lines' => [['item_id' => $item->id, 'qty' => 20]]])
        ->assertCreated()->assertJsonPath('record.expires_on', '2027-02-02')->json('record.id');
    expect(Pallet::find($id)->earliest_expiry)->toContain('2027-02-02');

    // topping up without the key leaves the date alone; sending an earlier one replaces it
    $this->postJson('/json/pallet-stock/fill', ['pallet_id' => $id, 'lines' => [['item_id' => $item->id, 'qty' => 5]]])->assertCreated();
    expect(Pallet::find($id)->earliest_expiry)->toContain('2027-02-02');
    $this->postJson('/json/pallet-stock/fill', ['pallet_id' => $id, 'expires_on' => '2027-01-01', 'lines' => [['item_id' => $item->id, 'qty' => 5]]])->assertCreated();
    expect(Pallet::find($id)->earliest_expiry)->toContain('2027-01-01');
});

test('empty pallets are left out of the location list and counts, and pallet lookup carries contents', function () {
    $item = stockItem(40);
    $loc = rack('A1');
    $id = $this->postJson('/json/pallet-stock/fill', ['location_id' => $loc->id, 'lines' => [['item_id' => $item->id, 'qty' => 10]]])->assertCreated()->json('record.id');
    $emptyId = $this->postJson('/json/pallet-stock/fill', ['location_id' => $loc->id, 'lines' => [['item_id' => $item->id, 'qty' => 5]]])->json('record.id');
    Pallet::whereKey($emptyId)->update(['status' => 'empty']);

    $this->getJson("/json/palletstatus/location/{$loc->id}")->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.id', $id);
    expect(collect($this->getJson('/json/palletstatus/summary')->json('locations'))->firstWhere('id', $loc->id)['total'])->toBe(1);

    $this->getJson('/json/palletstatus/lookup?tag='.$id)->assertOk()->assertJsonPath('record.contents.0.qty', 10)->assertJsonPath('record.sorted_from', null);
});
