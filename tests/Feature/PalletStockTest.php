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

test('picking from a pallet opens it, draws it down and empties it', function () {
    $item = stockItem(100);
    $pallet = Pallet::find($this->postJson('/json/pallet-stock/fill', ['lines' => [['item_id' => $item->id, 'qty' => 60]]])->json('record.id'));
    $order = Transaction::create([
        'type' => 'order', 'person_id' => Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera'])->id,
        'person_id_user' => User::factory()->create()->id, 'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
        'order_date' => now()->toDateString(),
    ]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $item->itemtype_id, 'qty_requested' => 60]);

    $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 25, 'pallet_tag' => $pallet->tag])->assertCreated();
    expect($pallet->fresh()->status)->toBe('open');
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

test('a pick with no pallet named uses the sorting-area pool first, then the oldest pallet, and never goes negative', function () {
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

    $res = $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 30])->assertCreated();
    expect($res->json('records'))->toHaveCount(2); // 10 from the pool, 20 off the oldest pallet
    expect(collect($res->json('records'))->pluck('qty_subtracted', 'stock_pallet_id')->all())->toBe(['' => 10, $first->id => 20]);
    $this->getJson('/json/pallet-stock/staging')->assertJsonCount(0, 'records');
    $this->getJson("/json/pallets/{$first->id}/contents")->assertJsonPath('record.contents.0.qty', 20);

    $over = $this->postJson("/json/order-filling/{$order->id}/lines/{$line->id}/fills", ['item_id' => $item->id, 'qty' => 80])->assertCreated();
    expect(collect($over->json('records'))->sum('qty_subtracted'))->toBe(80)->and($over->json('warning'))->toContain('10 more');
    expect($first->fresh()->status)->toBe('empty')->and($second->fresh()->status)->toBe('empty');
});
