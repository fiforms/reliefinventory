<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function orderCustomer(): Person
{
    return Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera']);
}

function orderItemtype(string $name = 'Bottled Water'): ItemType
{
    // the units table has no timestamp columns, so insert directly
    $unitId = DB::table('units')->insertGetId(['name' => 'Case', 'abbreviation' => 'cs']);
    $category = Category::firstOrCreate(['name' => 'Beverages']);

    return ItemType::create(['name' => $name, 'unit_id' => $unitId, 'category_id' => $category->id]);
}

test('creating an order requires a customer and starts as New Order with the acting user recorded', function () {
    $user = userWithPermissions('manage-orders');
    $impersonated = User::factory()->create();
    $customer = orderCustomer();

    // no person_id -> rejected
    $this->actingAs($user)->postJson('/json/orders', [])->assertStatus(422);

    // person_id_user and status are system-controlled, never from the client
    $this->actingAs($user)->postJson('/json/orders', [
        'person_id' => $customer->id,
        'person_id_user' => $impersonated->id,
        'status_id' => Transaction::statusId('Shipped'),
    ])->assertCreated();

    $order = Transaction::where('type', 'order')->latest('id')->first();
    expect($order->person_id)->toBe($customer->id)
        ->and($order->person_id_user)->toBe($user->id)
        ->and($order->status->name)->toBe('New Order')
        ->and($order->order_date)->toBe(now()->toDateString());
});

test('order entry endpoints require the manage-orders permission', function () {
    $user = userWithPermissions('general-access');
    $customer = orderCustomer();

    $this->actingAs($user)->getJson('/json/orders')->assertForbidden();
    $this->actingAs($user)->postJson('/json/orders', ['person_id' => $customer->id])->assertForbidden();
});

test('requested lines autosave one at a time and can be corrected or removed', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = orderItemtype();
    $order = $this->actingAs($user)
        ->postJson('/json/orders', ['person_id' => orderCustomer()->id])
        ->json('record');

    $line = $this->actingAs($user)->postJson('/json/orders/'.$order['id'].'/lines', [
        'itemtype_id' => $itemtype->id,
        'qty_requested' => 12,
        'comments' => 'phone order',
    ])->assertCreated()->json('record');

    expect($line['qty_requested'])->toBe(12)
        ->and($line['itemtype']['name'])->toBe('Bottled Water');

    // correcting (also how duplicate entries get combined)
    $this->actingAs($user)->putJson('/json/orders/'.$order['id'].'/lines/'.$line['id'], [
        'qty_requested' => 20,
    ])->assertOk();
    expect(Transaction::find($order['id'])->orderLines()->first()->qty_requested)->toBe(20);

    $this->actingAs($user)->deleteJson('/json/orders/'.$order['id'].'/lines/'.$line['id'])->assertOk();
    expect(Transaction::find($order['id'])->orderLines()->count())->toBe(0);
});

test('an order locks against intake edits once it leaves New Order status', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = orderItemtype();
    $order = Transaction::create([
        'type' => 'order',
        'person_id' => orderCustomer()->id,
        'person_id_user' => $user->id,
        'status_id' => Transaction::statusId(Transaction::STATUS_FILLING),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->postJson('/json/orders/'.$order->id.'/lines', [
        'itemtype_id' => $itemtype->id,
        'qty_requested' => 5,
    ])->assertStatus(409);

    $this->actingAs($user)->patchJson('/json/orders/'.$order->id, [
        'comments' => 'too late',
    ])->assertStatus(409);

    $this->actingAs($user)->deleteJson('/json/orders/'.$order->id)->assertStatus(409);
});

test('the order list splits open orders from completed ones', function () {
    $user = userWithPermissions('manage-orders');
    $customer = orderCustomer();

    $make = fn (string $status) => Transaction::create([
        'type' => 'order',
        'person_id' => $customer->id,
        'person_id_user' => $user->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => now()->toDateString(),
    ]);
    $open = $make(Transaction::STATUS_NEW_ORDER);
    $filling = $make(Transaction::STATUS_FILLING);
    $shipped = $make(Transaction::STATUS_SHIPPED);

    $response = $this->actingAs($user)->getJson('/json/orders')->assertOk()->json();

    expect(collect($response['open'])->pluck('id'))->toContain($open->id, $filling->id)
        ->not->toContain($shipped->id)
        ->and(collect($response['recent'])->pluck('id'))->toContain($shipped->id);
});

test('stock hints aggregate usable ledger quantity per itemtype and are staff-gated', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = orderItemtype();
    $packagetypeId = DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);
    $item = Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => 'Water 24pk']);

    $donation = Transaction::create([
        'type' => 'donation',
        'person_id_user' => $user->id,
        'status_id' => Transaction::statusId('Complete'),
        'order_date' => now()->toDateString(),
    ]);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 40, 'disposition' => 'usable']);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 10, 'disposition' => 'trashed']);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_subtracted' => 15]);

    $hints = $this->actingAs($user)->getJson('/json/orders/stock-hints')->assertOk()->json('hints');
    expect((int) $hints[$itemtype->id])->toBe(25);

    // advisory numbers are for order-entry staff only
    $this->actingAs(userWithPermissions('general-access'))
        ->getJson('/json/orders/stock-hints')->assertForbidden();
});

test('people are serialized with a combined full_name, falling back to organization', function () {
    $user = userWithPermissions('manage-people');
    Person::create(['first_name' => 'Dana', 'last_name' => 'Fields', 'organization' => 'Relief Org']);
    // organization-only records carry empty (NOT NULL) name columns
    Person::create(['organization' => 'Mercy Chest', 'first_name' => '', 'last_name' => '']);

    $records = $this->actingAs($user)->getJson('/json/people')->assertOk()->json('records');
    $names = collect($records)->pluck('full_name');

    expect($names)->toContain('Dana Fields')
        ->and($names)->toContain('Mercy Chest');
});
