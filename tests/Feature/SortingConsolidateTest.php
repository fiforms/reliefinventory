<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\Pallet;
use Illuminate\Support\Facades\DB;

function consolidateItem(string $name): Item
{
    static $n = 0;
    $n++;
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $itemtype = ItemType::create([
        'name' => $name, 'family' => sprintf('%04d', 900 + $n), 'variant' => '00', 'status' => 'orderable',
        'unit_id' => $unitId, 'category_id' => Category::firstOrCreate(['name' => 'Food'])->id,
    ]);
    $packagetypeId = DB::table('packagetypes')->first()->id
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Each', 'plural' => 'Each']);

    return Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => $name]);
}

function consolidatePallet(): string
{
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'received']);

    return 'R'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT);
}

test('consolidate sums identical lines and leaves different ones alone, with totals unchanged', function () {
    $sorter = userWithPermissions('manage-sorting');
    $soup = consolidateItem('Soup');
    $beans = consolidateItem('Beans');
    $palletA = consolidatePallet();
    $palletB = consolidatePallet();
    $sessionId = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');

    $add = fn (array $line) => $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/lines', $line)->assertCreated();
    foreach ([2, 3, 5] as $qty) {
        $add(['item_id' => $soup->id, 'qty' => $qty, 'disposition' => 'usable', 'item_condition' => 'new', 'pallet_tag' => $palletA]);
    }
    $add(['item_id' => $soup->id, 'qty' => 1, 'disposition' => 'usable', 'item_condition' => 'used', 'pallet_tag' => $palletA]); // other condition
    $add(['item_id' => $soup->id, 'qty' => 4, 'disposition' => 'trashed', 'pallet_tag' => $palletA]);                              // other disposition
    $add(['item_id' => $soup->id, 'qty' => 6, 'disposition' => 'usable', 'item_condition' => 'new', 'pallet_tag' => $palletB]);  // other pallet
    $add(['item_id' => $beans->id, 'qty' => 7, 'disposition' => 'usable', 'item_condition' => 'new', 'pallet_tag' => $palletA]);  // other item
    $add(['item_id' => $beans->id, 'qty' => 1, 'disposition' => 'usable', 'item_condition' => 'new', 'pallet_tag' => $palletA]);

    $before = ItemLedger::where('orderdonation_id', $sessionId)->sum('qty_added');
    $firstId = ItemLedger::where('orderdonation_id', $sessionId)->where('item_id', $soup->id)->where('qty_added', 2)->value('id');

    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/consolidate')
        ->assertOk()->assertJsonPath('merged', 3)->assertJsonCount(5, 'record.item_ledgers');

    $lines = ItemLedger::where('orderdonation_id', $sessionId)->get();
    expect($lines)->toHaveCount(5)
        ->and($lines->sum('qty_added'))->toBe((int) $before)
        ->and($lines->firstWhere('id', $firstId)->qty_added)->toBe(10)         // 2+3+5 kept on the earliest row
        ->and($lines->where('item_id', $beans->id)->first()->qty_added)->toBe(8);

    // running it again finds nothing more to do
    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/consolidate')->assertOk()->assertJsonPath('merged', 0);
});

test('lines entered by different people are not merged', function () {
    $item = consolidateItem('Soup');
    $one = userWithPermissions('manage-sorting');
    $two = userWithPermissions('manage-sorting');
    $sessionId = $this->actingAs($one)->postJson('/json/sorting-sessions')->json('record.id');
    foreach ([$one, $two] as $user) {
        $this->actingAs($user)->postJson('/json/sorting-sessions/'.$sessionId.'/lines', ['item_id' => $item->id, 'qty' => 2, 'disposition' => 'usable'])->assertCreated();
    }

    $this->actingAs($one)->postJson('/json/sorting-sessions/'.$sessionId.'/consolidate')->assertOk()->assertJsonPath('merged', 0);
    expect(ItemLedger::where('orderdonation_id', $sessionId)->count())->toBe(2);
});

test('a completed session cannot be consolidated, and sorting permission is required', function () {
    $sorter = userWithPermissions('manage-sorting');
    $item = consolidateItem('Soup');
    $sessionId = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');
    foreach ([1, 1] as $qty) {
        $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/lines', ['item_id' => $item->id, 'qty' => $qty, 'disposition' => 'usable'])->assertCreated();
    }

    $this->actingAs(userWithPermissions('general-access'))->postJson('/json/sorting-sessions/'.$sessionId.'/consolidate')->assertForbidden();

    $this->actingAs($sorter)->patchJson('/json/sorting-sessions/'.$sessionId, ['completed' => true])->assertOk();
    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/consolidate')->assertStatus(409);
    expect(ItemLedger::where('orderdonation_id', $sessionId)->count())->toBe(2);
});

test('consolidate keeps different expiration dates apart, merging only no-date with no-date and equal dates', function () {
    $sorter = userWithPermissions('manage-sorting');
    $soup = consolidateItem('Soup');
    $sessionId = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');

    foreach ([[1, null], [2, null], [3, '2027-01-01'], [4, '2027-01-01'], [5, '2027-06-01']] as [$qty, $date]) {
        $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/lines', [
            'item_id' => $soup->id, 'qty' => $qty, 'disposition' => 'usable', 'item_condition' => 'new', 'expires_on' => $date,
        ])->assertCreated();
    }

    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$sessionId.'/consolidate')
        ->assertOk()->assertJsonPath('merged', 2)->assertJsonCount(3, 'record.item_ledgers');

    $rows = ItemLedger::where('orderdonation_id', $sessionId)->orderBy('qty_added')->get();
    expect($rows->pluck('qty_added')->all())->toBe([3, 5, 7])
        ->and($rows->pluck('expires_on')->map(fn ($d) => $d ? substr((string) $d, 0, 10) : null)->all())->toBe([null, '2027-06-01', '2027-01-01']);
});

test('a pallet linked to another donation cannot be sorted into this session', function () {
    $sorter = userWithPermissions('manage-sorting');
    $item = consolidateItem('Rice');
    $mine = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');
    $other = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');

    $pallet = Pallet::create(['kind' => 'R', 'status' => 'sorting', 'datepacked' => now()->toDateString(), 'orderdonation_id' => $other]);
    $tag = 'R'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT);
    $line = ['item_id' => $item->id, 'qty' => 1, 'disposition' => 'usable', 'pallet_tag' => $tag];

    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$mine.'/lines', $line)
        ->assertStatus(422)->assertJsonPath('errors.pallet_tag.0', fn ($m) => str_contains($m, 'different donation'));
    expect(ItemLedger::where('pallet_id', $pallet->id)->count())->toBe(0);

    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$other.'/lines', $line)->assertCreated();
});

test('an unlinked pre-printed pallet label can be sorted into any session', function () {
    $sorter = userWithPermissions('manage-sorting');
    $item = consolidateItem('Pasta');
    $session = $this->actingAs($sorter)->postJson('/json/sorting-sessions')->json('record.id');

    $this->actingAs($sorter)->postJson('/json/sorting-sessions/'.$session.'/lines', [
        'item_id' => $item->id, 'qty' => 1, 'disposition' => 'usable', 'pallet_tag' => consolidatePallet(),
    ])->assertCreated();
});
