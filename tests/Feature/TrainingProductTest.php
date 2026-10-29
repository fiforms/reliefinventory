<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Scan Training Products: each scan counts one unit of a known barcode; an unknown one is handed back for Add New Item.

use App\Models\CartonCode;
use App\Models\ItemLedger;
use App\Models\TrainingProduct;
use Illuminate\Support\Facades\DB;

function trainingScanItem(string $upc = '012345678905')
{
    $packagetypeId = DB::table('packagetypes')->value('id') ?? DB::table('packagetypes')->insertGetId(['singular' => 'Can', 'plural' => 'Cans']);

    return urrItemtype('Canned food')->items()->create(['packagetypes_id' => $packagetypeId, 'pluscode' => '0000', 'size' => 1,
        'case_qty' => 1, 'active' => true, 'description' => 'Green beans', 'upc' => $upc]);
}

beforeEach(fn () => trainingWarehouse());

test('the product list needs manage-training', function () {
    $this->actingAs(userWithPermissions('manage-sorting'));

    $this->getJson('/json/training/products')->assertForbidden();
    $this->postJson('/json/training/products/scan', ['code' => '012345678905'])->assertForbidden();
});

test('scanning a known barcode counts one unit each time, and is not stock', function () {
    $item = trainingScanItem();
    $user = userWithPermissions('manage-training');
    $this->actingAs($user);

    $this->postJson('/json/training/products/scan', ['code' => '012345678905'])->assertOk()->assertJsonPath('scan.status', 'matched');
    $response = $this->postJson('/json/training/products/scan', ['code' => '0012345678905'])->assertOk(); // EAN-13 spelling

    expect($response->json('units'))->toBe(2)
        ->and($response->json('records.0.name'))->toBe('Green beans')
        ->and($response->json('plan.target'))->toBeGreaterThan(0)
        ->and(TrainingProduct::sole()->only(['item_id', 'qty', 'person_id_user']))->toBe(['item_id' => $item->id, 'qty' => 2, 'person_id_user' => $user->id])
        ->and(ItemLedger::count())->toBe(0);
});

test('a known case barcode counts every unit in it', function () {
    $item = trainingScanItem();
    CartonCode::create(['code' => '10012345678902', 'item_id' => $item->id, 'qty_per_carton' => 12]);
    $this->actingAs(userWithPermissions('manage-training'));

    $this->postJson('/json/training/products/scan', ['code' => '10012345678902'])->assertOk();

    expect(TrainingProduct::sole()->qty)->toBe(12);
});

test('an unknown barcode is handed back without counting; a bad one is refused', function () {
    $this->actingAs(userWithPermissions('manage-training'));

    $this->postJson('/json/training/products/scan', ['code' => '036000291452'])->assertOk()
        ->assertJsonPath('scan.status', 'unknown')->assertJsonPath('units', 0);
    $this->postJson('/json/training/products/scan', ['code' => '036000291453'])->assertStatus(422);
    expect(TrainingProduct::count())->toBe(0);
});

test('counts can be added by hand, corrected, removed and cleared', function () {
    $item = trainingScanItem();
    $this->actingAs(userWithPermissions('manage-training'));

    $id = $this->postJson('/json/training/products', ['item_id' => $item->id, 'qty' => 5])->assertCreated()->json('product_id');
    $this->postJson('/json/training/products', ['item_id' => $item->id])->assertCreated()->assertJsonPath('units', 6);
    $this->patchJson("/json/training/products/$id", ['qty' => 3])->assertOk()->assertJsonPath('units', 3);
    $this->patchJson("/json/training/products/$id", ['qty' => 0])->assertOk()->assertJsonPath('units', 0);
    expect(TrainingProduct::count())->toBe(0);

    $id = $this->postJson('/json/training/products', ['item_id' => $item->id])->json('product_id');
    $this->deleteJson("/json/training/products/$id")->assertOk()->assertJsonPath('records', []);
    $this->postJson('/json/training/products', ['item_id' => $item->id]);
    $this->deleteJson('/json/training/products')->assertOk()->assertJsonPath('units', 0);
    expect($item->fresh())->not->toBeNull();
});
