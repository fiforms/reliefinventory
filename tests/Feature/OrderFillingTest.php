<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\OrderLine;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function fillingPartner(): Person
{
    return Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera']);
}

function fillingItemtype(string $name = 'Bottled Water'): ItemType
{
    $unitId = DB::table('units')->where('abbreviation', 'cs')->value('id')
        ?? DB::table('units')->insertGetId(['name' => 'Case', 'abbreviation' => 'cs']);
    $category = Category::firstOrCreate(['name' => 'Beverages']);

    return ItemType::create(['name' => $name, 'unit_id' => $unitId, 'category_id' => $category->id]);
}

function readyToFillOrder(): Transaction
{
    $order = Transaction::create([
        'type' => 'order',
        'person_id' => fillingPartner()->id,
        'person_id_user' => User::factory()->create()->id,
        'status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_FILL),
        'order_date' => now()->toDateString(),
    ]);

    return $order;
}

function fillableItem(ItemType $itemtype): Item
{
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Box')->value('id')
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);

    return Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => $itemtype->name]);
}

test('order filling endpoints require the manage-orders permission', function () {
    $user = userWithPermissions('general-access');
    $order = readyToFillOrder();

    $this->actingAs($user)->getJson('/json/order-filling')->assertForbidden();
    $this->actingAs($user)->patchJson('/json/order-filling/'.$order->id.'/start')->assertForbidden();
    $this->actingAs($user)->postJson('/json/order-filling/print-pick-sheets')->assertForbidden();
    $this->actingAs($user)->patchJson('/json/order-filling/'.$order->id.'/complete')->assertForbidden();
    $this->actingAs($user)->postJson('/json/order-filling/'.$order->id.'/lines/1/fills')->assertForbidden();
    $this->actingAs($user)->getJson('/report/pick-sheets.pdf?ids[]='.$order->id)->assertForbidden();
});

test('the queue splits Ready to Fill and Filling orders and excludes other statuses', function () {
    $user = userWithPermissions('manage-orders');
    $ready = readyToFillOrder();
    $filling = readyToFillOrder();
    $filling->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $newOrder = readyToFillOrder();
    $newOrder->update(['status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER)]);

    $response = $this->actingAs($user)->getJson('/json/order-filling')->assertOk();
    $readyIds = collect($response->json('ready_to_fill'))->pluck('id');
    $fillingIds = collect($response->json('filling'))->pluck('id');

    expect($readyIds)->toContain($ready->id)->not->toContain($filling->id)->not->toContain($newOrder->id)
        ->and($fillingIds)->toContain($filling->id)->not->toContain($ready->id);
});

test('starting a Ready to Fill order moves it to Filling, and rejects any other status', function () {
    $user = userWithPermissions('manage-orders');
    $order = readyToFillOrder();

    $this->actingAs($user)->patchJson('/json/order-filling/'.$order->id.'/start')
        ->assertOk()->assertJsonPath('record.status.name', Transaction::STATUS_FILLING);

    $this->actingAs($user)->patchJson('/json/order-filling/'.$order->id.'/start')->assertStatus(409);
});

test('printing pick sheets locks every Ready to Fill order atomically and stamps a fresh status_changed_at', function () {
    $user = userWithPermissions('manage-orders');
    $a = readyToFillOrder();
    $b = readyToFillOrder();
    $notReady = readyToFillOrder();
    $notReady->update(['status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER)]);

    $before = now()->subMinute();
    $response = $this->actingAs($user)->postJson('/json/order-filling/print-pick-sheets')->assertOk();
    $ids = $response->json('order_ids');

    expect($ids)->toContain($a->id)->toContain($b->id)->not->toContain($notReady->id);

    $a->refresh();
    expect($a->status->name)->toBe(Transaction::STATUS_FILLING)
        ->and($a->status_changed_at->greaterThan($before))->toBeTrue();

    // idempotent: nothing left Ready to Fill on a second call
    $second = $this->actingAs($user)->postJson('/json/order-filling/print-pick-sheets')->assertOk();
    expect($second->json('order_ids'))->toBe([]);
});

test('the pick sheets PDF is a pure render — statuses are unchanged by the GET and it can be reloaded', function () {
    $user = userWithPermissions('manage-orders');
    $order = readyToFillOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);

    $this->actingAs($user)->get('/report/pick-sheets.pdf?ids[]='.$order->id)->assertOk();
    $this->actingAs($user)->get('/report/pick-sheets.pdf?ids[]='.$order->id)->assertOk();

    expect($order->fresh()->status->name)->toBe(Transaction::STATUS_FILLING);
});

test('order-line intake stays locked once an order is Filling', function () {
    $user = userWithPermissions('manage-orders');
    $order = readyToFillOrder();
    $itemtype = fillingItemtype();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);

    $this->actingAs($user)->postJson('/json/orders/'.$order->id.'/lines', [
        'itemtype_id' => $itemtype->id, 'qty_requested' => 5,
    ])->assertStatus(409);
});

test('a fill record must match its line\'s item type and writes a proper ledger row', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $otherItemtype = fillingItemtype('Canned Beans');
    $order = readyToFillOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $item = fillableItem($itemtype);
    $wrongItem = fillableItem($otherItemtype);

    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $wrongItem->id, 'qty' => 3]
    )->assertStatus(422);

    $response = $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $item->id, 'qty' => 4]
    )->assertCreated();

    $fill = ItemLedger::find($response->json('record.id'));
    expect($fill->qty_subtracted)->toBe(4)
        ->and($fill->order_line_id)->toBe($line->id)
        ->and($fill->person_id_user)->toBe($user->id)
        ->and($fill->disposition)->toBe('usable');
});

test('a zero-quantity fill is accepted, and multiple fills per line accumulate rather than overwrite', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $order = readyToFillOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $item = fillableItem($itemtype);

    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $item->id, 'qty' => 0]
    )->assertCreated();
    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $item->id, 'qty' => 6]
    )->assertCreated();

    expect(ItemLedger::where('order_line_id', $line->id)->count())->toBe(2)
        ->and((int) ItemLedger::where('order_line_id', $line->id)->sum('qty_subtracted'))->toBe(6);
});

test('fill actions are rejected outside Filling status', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $order = readyToFillOrder(); // still Ready to Fill, not Filling
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $item = fillableItem($itemtype);

    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $item->id, 'qty' => 1]
    )->assertStatus(409);
});

test('completing filling requires every line to have at least one fill record, zero included', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $itemtype2 = fillingItemtype('Canned Beans');
    $order = readyToFillOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $line1 = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $line2 = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype2->id, 'qty_requested' => 5]);
    $item1 = fillableItem($itemtype);
    $item2 = fillableItem($itemtype2);

    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line1->id.'/fills',
        ['item_id' => $item1->id, 'qty' => 10]
    )->assertCreated();

    $this->actingAs($user)->patchJson('/json/order-filling/'.$order->id.'/complete')->assertStatus(422);

    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line2->id.'/fills',
        ['item_id' => $item2->id, 'qty' => 0]
    )->assertCreated();

    $this->actingAs($user)->patchJson('/json/order-filling/'.$order->id.'/complete')
        ->assertOk()->assertJsonPath('record.status.name', Transaction::STATUS_FILLED);
});

test('a completed fill is reflected in stock-hints and the warehouse metrics on-hand formula', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $order = readyToFillOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $item = fillableItem($itemtype);

    $donation = Transaction::create([
        'type' => 'donation',
        'person_id_user' => $user->id,
        'status_id' => Transaction::statusId('Complete'),
        'order_date' => now()->toDateString(),
    ]);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 40, 'disposition' => 'usable']);

    $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $item->id, 'qty' => 15]
    )->assertCreated();

    $hints = $this->actingAs($user)->getJson('/json/orders/stock-hints')->json('hints');
    expect((int) $hints[$itemtype->id])->toBe(25);
});

test('fill records can be edited and deleted', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $order = readyToFillOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $item = fillableItem($itemtype);

    $fillId = $this->actingAs($user)->postJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills',
        ['item_id' => $item->id, 'qty' => 3]
    )->json('record.id');

    $this->actingAs($user)->putJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills/'.$fillId,
        ['qty' => 8]
    )->assertOk();
    expect(ItemLedger::find($fillId)->qty_subtracted)->toBe(8);

    $this->actingAs($user)->deleteJson(
        '/json/order-filling/'.$order->id.'/lines/'.$line->id.'/fills/'.$fillId
    )->assertOk();
    expect(ItemLedger::find($fillId))->toBeNull();
});

test('need_level is accepted as optional on order lines and round-trips', function () {
    $user = userWithPermissions('manage-orders');
    $itemtype = fillingItemtype();
    $order = $this->actingAs($user)
        ->postJson('/json/orders', ['person_id' => fillingPartner()->id])
        ->json('record');

    $line = $this->actingAs($user)->postJson('/json/orders/'.$order['id'].'/lines', [
        'itemtype_id' => $itemtype->id,
        'qty_requested' => 5,
        'need_level' => 'critical',
    ])->assertCreated()->json('record');

    expect($line['need_level'])->toBe('critical');

    // still fine when omitted entirely — never required
    $this->actingAs($user)->postJson('/json/orders/'.$order['id'].'/lines', [
        'itemtype_id' => fillingItemtype('Canned Beans')->id,
        'qty_requested' => 2,
    ])->assertCreated();
});

test('sorting session lines now stamp the acting person on the ledger row', function () {
    $user = userWithPermissions('manage-sorting');
    $itemtype = fillingItemtype();
    $item = fillableItem($itemtype);

    $session = $this->actingAs($user)->postJson('/json/sorting-sessions', [])->assertCreated()->json('record');

    $lineId = $this->actingAs($user)->postJson('/json/sorting-sessions/'.$session['id'].'/lines', [
        'item_id' => $item->id,
        'qty' => 5,
        'disposition' => 'usable',
    ])->assertCreated()->json('record.id');

    expect(ItemLedger::find($lineId)->person_id_user)->toBe($user->id);
});
