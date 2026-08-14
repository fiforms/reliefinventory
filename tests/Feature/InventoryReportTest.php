<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Status;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

function reportItemType(string $name, string $family): ItemType
{
    // the units table has no timestamp columns, so insert directly
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $category = Category::firstOrCreate(['name' => 'Beverages']);

    return ItemType::create(['name' => $name, 'family' => $family, 'variant' => '00', 'status' => 'orderable', 'unit_id' => $unitId, 'category_id' => $category->id]);
}

function ledgerDonation(): Transaction
{
    return Transaction::create([
        'type' => 'donation',
        'status_id' => Status::firstOrCreate(['name' => 'Complete'])->id,
        'order_date' => now()->toDateString(),
    ]);
}

test('inventory report requires the view-reports permission', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/reports/inventory')->assertForbidden();
});

test('on-hand nets usable additions against subtractions, excluding other dispositions', function () {
    $user = userWithPermissions('view-reports');
    $itemtype = reportItemType('Bottled Water', '0100');
    $packagetypeId = DB::table('packagetypes')->insertGetId(['singular' => 'Case', 'plural' => 'Cases']);
    $item = Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => 'Water 24pk']);

    $donation = ledgerDonation();
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 50, 'disposition' => 'usable']);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 8, 'disposition' => 'trashed']);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 3, 'disposition' => 'outdated']);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 2, 'disposition' => 'diverted']);
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_subtracted' => 15]);

    $records = collect($this->actingAs($user)->getJson('/json/reports/inventory')->assertOk()->json('records'));
    $record = $records->firstWhere('id', $itemtype->id);

    expect($record['on_hand'])->toBe(35)
        ->and($record['trashed'])->toBe(8)
        ->and($record['outdated'])->toBe(3)
        ->and($record['diverted'])->toBe(2)
        ->and($record['items'])->toHaveCount(1)
        ->and($record['items'][0]['on_hand'])->toBe(35);
});

test('itemtypes with no ledger activity report zero rather than being omitted', function () {
    $user = userWithPermissions('view-reports');
    $itemtype = reportItemType('Never Received', '0200');

    $records = collect($this->actingAs($user)->getJson('/json/reports/inventory')->assertOk()->json('records'));
    $record = $records->firstWhere('id', $itemtype->id);

    expect($record['on_hand'])->toBe(0)
        ->and($record)->not->toBeNull();
});

test('ledger rows that omit disposition fall back to the column default of usable', function () {
    $user = userWithPermissions('view-reports');
    $itemtype = reportItemType('Canned Beans', '0300');
    $packagetypeId = DB::table('packagetypes')->insertGetId(['singular' => 'Can', 'plural' => 'Cans']);
    $item = Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => 'Beans']);

    $donation = ledgerDonation();
    $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => 20]);

    $records = collect($this->actingAs($user)->getJson('/json/reports/inventory')->assertOk()->json('records'));
    $record = $records->firstWhere('id', $itemtype->id);

    expect($record['on_hand'])->toBe(20);
});
