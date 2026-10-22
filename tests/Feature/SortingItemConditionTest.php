<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use Illuminate\Support\Facades\DB;

// Feedback #42: sorters mark usable goods New or Usable (used, good
// condition). Both stay disposition=usable and count toward on-hand; the
// split is item_condition, reported as "Received New".

function conditionItem(): Item
{
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $itemtype = ItemType::create([
        'name' => 'Shower Chair', 'family' => '0700', 'variant' => '00', 'status' => 'orderable',
        'unit_id' => $unitId, 'category_id' => Category::firstOrCreate(['name' => 'DME'])->id,
    ]);
    $packagetypeId = DB::table('packagetypes')->insertGetId(['singular' => 'Each', 'plural' => 'Each']);

    return Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => 'Shower Chair']);
}

test('sorting records item_condition for usable lines only, and both new and used count toward on-hand', function () {
    $sorter = userWithPermissions('manage-sorting');
    $item = conditionItem();
    $sessionId = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->assertSuccessful()->json('record.id');

    $add = fn (array $line) => $this->actingAs($sorter)
        ->postJson('/json/sorting-sessions/'.$sessionId.'/lines', ['item_id' => $item->id] + $line);

    $add(['qty' => 4, 'disposition' => 'usable', 'item_condition' => 'new'])->assertCreated()
        ->assertJsonPath('record.item_condition', 'new');
    $add(['qty' => 6, 'disposition' => 'usable', 'item_condition' => 'used'])->assertCreated();
    // a condition sent with a non-usable disposition is dropped, not stored
    $add(['qty' => 2, 'disposition' => 'trashed', 'item_condition' => 'new'])->assertCreated()
        ->assertJsonPath('record.item_condition', null);
    $add(['qty' => 1, 'disposition' => 'usable', 'item_condition' => 'broken'])->assertStatus(422);

    $record = collect($this->actingAs(userWithPermissions('view-reports'))->getJson('/json/reports/inventory')->json('records'))
        ->firstWhere('id', $item->itemtype_id);
    expect($record['on_hand'])->toBe(10)
        ->and($record['received_new'])->toBe(4)
        ->and($record['trashed'])->toBe(2);
});

test('changing a line away from usable clears its condition', function () {
    $sorter = userWithPermissions('manage-sorting');
    $item = conditionItem();
    $sessionId = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');
    $lineId = $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/lines', [
        'item_id' => $item->id, 'qty' => 3, 'disposition' => 'usable', 'item_condition' => 'new',
    ])->json('record.id');

    $this->actingAs($sorter)->putJson('/json/sorting-sessions/'.$sessionId.'/lines/'.$lineId, ['disposition' => 'outdated'])
        ->assertOk();

    expect(ItemLedger::find($lineId)->item_condition)->toBeNull();
});

test('sorting lines stamp the acting person on the ledger row', function () {
    $user = userWithPermissions('manage-sorting');
    $item = conditionItem();

    $session = $this->actingAs($user)->postJson('/json/sorting-sessions', [])->assertCreated()->json('record');

    $lineId = $this->actingAs($user)->postJson('/json/sorting-sessions/'.$session['id'].'/lines', [
        'item_id' => $item->id,
        'qty' => 5,
        'disposition' => 'usable',
    ])->assertCreated()->json('record.id');

    expect(ItemLedger::find($lineId)->person_id_user)->toBe($user->id);
});
