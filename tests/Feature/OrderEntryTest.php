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

function orderPartner(): Person
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

test('creating an order requires a partner and starts as New Order with the acting user recorded', function () {
    $user = userWithPermissions('manage-orders');
    $impersonated = User::factory()->create();
    $partner = orderPartner();

    // no person_id -> rejected
    $this->actingAs($user)->postJson('/json/orders', [])->assertStatus(422);

    // person_id_user and status are system-controlled, never from the client
    $this->actingAs($user)->postJson('/json/orders', [
        'person_id' => $partner->id,
        'person_id_user' => $impersonated->id,
        'status_id' => Transaction::statusId('Shipped'),
    ])->assertCreated();

    $order = Transaction::where('type', 'order')->latest('id')->first();
    expect($order->person_id)->toBe($partner->id)
        ->and($order->person_id_user)->toBe($user->id)
        ->and($order->status->name)->toBe('New Order')
        ->and($order->order_date)->toBe(now()->toDateString());
});

test('order entry endpoints require the manage-orders permission', function () {
    $user = userWithPermissions('general-access');
    $partner = orderPartner();

    $this->actingAs($user)->getJson('/json/orders')->assertForbidden();
    $this->actingAs($user)->postJson('/json/orders', ['person_id' => $partner->id])->assertForbidden();
});

test('requested lines autosave one at a time and can be corrected or removed', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = orderItemtype();
    $order = $this->actingAs($user)
        ->postJson('/json/orders', ['person_id' => orderPartner()->id])
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
        'person_id' => orderPartner()->id,
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
    $partner = orderPartner();

    $make = fn (string $status) => Transaction::create([
        'type' => 'order',
        'person_id' => $partner->id,
        'person_id_user' => $user->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => now()->toDateString(),
    ]);
    $open = $make(Transaction::STATUS_NEW_ORDER);
    $readyToFill = $make(Transaction::STATUS_READY_TO_FILL);
    $filling = $make(Transaction::STATUS_FILLING);
    $shipped = $make(Transaction::STATUS_SHIPPED);
    $delivered = $make(Transaction::STATUS_DELIVERED);
    $completed = $make(Transaction::STATUS_COMPLETED);

    $response = $this->actingAs($user)->getJson('/json/orders')->assertOk()->json();

    // Completed (manager approved the signed BOL) is this order type's
    // real terminus — Shipped and Delivered are both still "open" (a load
    // can sit Shipped a while before the driver returns proof of delivery,
    // and Delivered just means it's awaiting manager review).
    expect(collect($response['open'])->pluck('id'))->toContain($open->id, $readyToFill->id, $filling->id, $shipped->id, $delivered->id)
        ->not->toContain($completed->id)
        ->and(collect($response['recent'])->pluck('id'))->toContain($completed->id);
});

test('completing an order requires a fulfillment method and moves it to Ready to Fill, locking further edits', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = orderItemtype();
    $partner = orderPartner();
    $order = $this->actingAs($user)
        ->postJson('/json/orders', ['person_id' => $partner->id])
        ->json('record');

    $this->actingAs($user)->postJson('/json/orders/'.$order['id'].'/lines', [
        'itemtype_id' => $itemtype->id,
        'qty_requested' => 3,
    ])->assertCreated();

    // fulfillment_method is the one required field on the review screen
    $this->actingAs($user)->patchJson('/json/orders/'.$order['id'].'/complete', [])
        ->assertStatus(422);

    $response = $this->actingAs($user)->patchJson('/json/orders/'.$order['id'].'/complete', [
        'comments' => 'leave at the loading dock',
        'fulfillment_method' => 'delivery',
        'needed_by_date' => now()->addDays(3)->toDateString(),
        'delivery_days' => ['Mon', 'Wed', 'Fri'],
        'preferred_time' => '10am - 2pm',
        'contact_name' => 'Pat Rivera',
        'contact_phone' => '555-0100',
        'other_needs' => 'tarps, not in catalog',
    ])->assertOk()->json('record');

    expect($response['status']['name'])->toBe('Ready to Fill')
        ->and($response['fulfillment_method'])->toBe('delivery')
        ->and($response['delivery_days'])->toBe(['Mon', 'Wed', 'Fri'])
        ->and($response['preferred_time'])->toBe('10am - 2pm')
        ->and($response['other_needs'])->toBe('tarps, not in catalog');

    // now locked, same as any other non-New-Order status
    $this->actingAs($user)->postJson('/json/orders/'.$order['id'].'/lines', [
        'itemtype_id' => $itemtype->id,
        'qty_requested' => 1,
    ])->assertStatus(409);
    $this->actingAs($user)->patchJson('/json/orders/'.$order['id'].'/complete', [
        'fulfillment_method' => 'delivery',
    ])->assertStatus(409);
});

test('completing as pickup clears delivery_days and preferred_time even if submitted, since the warehouse controls those', function () {
    $user = userWithPermissions('manage-orders');
    $order = $this->actingAs($user)
        ->postJson('/json/orders', ['person_id' => orderPartner()->id])
        ->json('record');

    $response = $this->actingAs($user)->patchJson('/json/orders/'.$order['id'].'/complete', [
        'fulfillment_method' => 'pickup',
        'needed_by_date' => now()->addDays(2)->toDateString(),
        'delivery_days' => ['Tue'],
        'preferred_time' => 'noon',
    ])->assertOk()->json('record');

    expect($response['fulfillment_method'])->toBe('pickup')
        ->and($response['delivery_days'])->toBeNull()
        ->and($response['preferred_time'])->toBeNull()
        ->and($response['needed_by_date'])->not->toBeNull();
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
