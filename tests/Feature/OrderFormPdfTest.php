<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\OrderController;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

function orderFormItemType(string $name, string $family, string $status = 'orderable'): ItemType
{
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $category = Category::firstOrCreate(['name' => 'Beverages']);

    return ItemType::create([
        'name' => $name, 'family' => $family, 'variant' => '00', 'status' => $status,
        'unit_id' => $unitId, 'category_id' => $category->id,
    ]);
}

function orderFormItem(ItemType $itemType): Item
{
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Case')->value('id')
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Case', 'plural' => 'Cases']);

    return Item::create(['itemtype_id' => $itemType->id, 'packagetypes_id' => $packagetypeId, 'description' => $itemType->name]);
}

function stockDonation(Item $item, int $qty): void
{
    $donation = Transaction::create([
        'type' => 'donation',
        'status_id' => Transaction::statusId('Complete'),
        'order_date' => now()->toDateString(),
    ]);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => $qty, 'disposition' => 'usable']);
}

test('the order form PDF requires the manage-orders permission', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->get('/report/order-form.pdf')->assertForbidden();
});

test('the order form data only includes orderable item types with stock, and carries no quantity field at all', function () {
    $inStock = orderFormItemType('Bottled Water', '0100');
    stockDonation(orderFormItem($inStock), 40);

    $outOfStock = orderFormItemType('Canned Beans', '0200');
    orderFormItem($outOfStock); // no ledger activity — zero on hand

    $sortHold = orderFormItemType('Mystery Item', '0300', status: 'sort_hold');
    stockDonation(orderFormItem($sortHold), 40); // stocked, but not yet reviewed into a real number

    $this->actingAs(userWithPermissions('manage-orders'));
    $records = (new OrderController)->buildOrderFormRecords()->flatten(1);

    $names = $records->pluck('name');
    expect($names)->toContain('Bottled Water')
        ->not->toContain('Canned Beans')
        ->not->toContain('Mystery Item')
        // the query result must structurally never carry a quantity — not just
        // "not rendered," the field doesn't exist to render in the first place
        ->and($records->first())->not->toHaveKey('on_hand')
        ->not->toHaveKey('qty');
});

// PDF byte-rendering needs spatie/browsershot's real headless-Chrome
// process, and the resulting content stream is compressed, so byte-level
// content assertions aren't meaningful here — the data itself is verified
// above via buildOrderFormRecords() directly. This just confirms the route
// end-to-end doesn't error.
test('the order form PDF route renders successfully for a permitted user', function () {
    $response = $this->actingAs(userWithPermissions('manage-orders'))->get('/report/order-form.pdf');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});
