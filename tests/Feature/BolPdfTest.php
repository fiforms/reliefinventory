<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\OrderController;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\OrderLine;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function bolPartner(): Person
{
    return Person::create([
        'first_name' => 'Pat', 'last_name' => 'Rivera',
        'address' => '123 Main St', 'city' => 'Statesville', 'state' => 'NC', 'zip' => '28677',
    ]);
}

function bolItemtype(): ItemType
{
    // Look up by name (not abbreviation) — a "Case" unit is now real
    // seeded/migrated data with its own abbreviation ("case"), so an
    // abbreviation='cs' lookup would miss it and re-insert a colliding name.
    $unitId = DB::table('units')->where('name', 'Case')->value('id')
        ?? DB::table('units')->insertGetId(['name' => 'Case', 'abbreviation' => 'cs']);
    $category = Category::firstOrCreate(['name' => 'Beverages']);

    return ItemType::create(['name' => 'Bottled Water', 'unit_id' => $unitId, 'category_id' => $category->id]);
}

function bolItem(ItemType $itemtype): Item
{
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Box')->value('id')
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);

    return Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => $itemtype->name]);
}

function filledOrder(): Transaction
{
    $itemtype = bolItemtype();
    $item = bolItem($itemtype);

    $order = Transaction::create([
        'type' => 'order',
        'person_id' => bolPartner()->id,
        'person_id_user' => User::factory()->create()->id,
        'status_id' => Transaction::statusId(Transaction::STATUS_FILLED),
        'order_date' => now()->toDateString(),
        'fulfillment_method' => 'delivery',
        'contact_name' => 'Pat Rivera',
        'contact_phone' => '555-1234',
        'special_instructions' => 'Use the loading dock around back.',
    ]);

    $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => 10]);
    $order->itemLedgers()->create(['item_id' => $item->id, 'order_line_id' => $line->id, 'qty_subtracted' => 10, 'disposition' => 'usable']);

    return $order;
}

test('generating a BOL requires the manage-orders permission', function () {
    $order = filledOrder();

    $this->actingAs(userWithPermissions('general-access'))
        ->get('/report/bol/'.$order->id.'.pdf')
        ->assertForbidden();
});

test('a BOL cannot be generated before an order is Filled', function () {
    $order = filledOrder();
    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->get('/report/bol/'.$order->id.'.pdf')
        ->assertStatus(409);
});

test('generating a BOL assigns a bol_number once and reuses it on reprint', function () {
    $order = filledOrder();
    expect($order->bol_number)->toBeNull();

    $user = userWithPermissions('manage-orders');

    $first = $this->actingAs($user)->get('/report/bol/'.$order->id.'.pdf');
    $first->assertOk();
    expect($first->headers->get('Content-Type'))->toContain('application/pdf');

    $order->refresh();
    expect($order->bol_number)->not->toBeNull();
    $assignedNumber = $order->bol_number;

    $this->actingAs($user)->get('/report/bol/'.$order->id.'.pdf')->assertOk();
    $order->refresh();
    expect($order->bol_number)->toBe($assignedNumber);
});

test('a shipping label is a single 4 x 6.5 in page with the order number', function () {
    $order = filledOrder();
    $order->update(['pallet_count' => 3, 'special_instructions' => str_repeat('Back dock only. ', 40)]);

    $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
    app(OrderController::class)->shippingLabelBuilder($order->id)->save($path);

    expect(pdfInfo($path))->toMatch('/Pages:\s+1\b/')->toMatch('/Page size:\s+288(\.\d+)? x 468(\.\d+)? pts/');
    expect(pdfText($path))->toContain('ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT))
        ->toContain('Rivera')->toContain('123 Main St')->toContain('...');
    unlink($path);
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');

test('a shipping label needs manage-orders and a filled order', function () {
    $order = filledOrder();
    $this->actingAs(userWithPermissions('general-access'))->get('/report/shipping-label/'.$order->id.'.pdf')->assertForbidden();

    $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_FILLING)]);
    $this->actingAs(userWithPermissions('manage-orders'))->get('/report/shipping-label/'.$order->id.'.pdf')->assertStatus(409);
});
