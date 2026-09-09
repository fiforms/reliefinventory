<?php

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\PackageType;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

// Feedback #43: creating an item type auto-creates a generic Item row, and
// that row used to hardcode packagetypes_id => 1 ("assuming default
// package type ID"). If package type 1 didn't exist, the FK-constrained
// insert threw, and — since the whole store() wasn't in a transaction —
// left a half-created ItemType with no items behind it.
test('creating an item type still succeeds when package type id 1 does not exist', function () {
    $user = userWithPermissions('manage-itemtypes');
    $category = Category::create(['name' => 'Test Category']);
    $unit = Unit::first() ?? Unit::create(['abbreviation' => 'ea', 'name' => 'each-test', 'type' => 'each']);

    // Consume id 1 and delete it, so the surviving package type's id is
    // guaranteed not to be 1 — the old hardcoded literal would 500 here if
    // the bug were still present.
    $throwaway = PackageType::create(['singular' => 'Throwaway', 'plural' => 'Throwaways']);
    $throwaway->delete();
    $survivor = PackageType::create(['singular' => 'Survivor', 'plural' => 'Survivors']);
    expect($survivor->id)->not->toBe(1);

    $response = $this->actingAs($user)->postJson('/json/itemtypes', [
        'family' => '9001',
        'variant' => '00',
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'name' => 'Test Item Type',
        'active' => true,
    ]);

    $response->assertCreated();
    $itemType = ItemType::where('name', 'Test Item Type')->firstOrFail();
    $item = Item::where('itemtype_id', $itemType->id)->first();

    expect($item)->not->toBeNull()
        ->and($item->packagetypes_id)->toBe($survivor->id);
});

test('creating an item type fails cleanly with no orphan row when no package types exist at all', function () {
    $user = userWithPermissions('manage-itemtypes');
    $category = Category::create(['name' => 'Test Category 2']);
    $unit = Unit::first() ?? Unit::create(['abbreviation' => 'ea', 'name' => 'each-test-2', 'type' => 'each']);

    DB::table('packagetypes')->delete();

    $this->actingAs($user)->postJson('/json/itemtypes', [
        'family' => '9002',
        'variant' => '00',
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'name' => 'Orphan Test Item Type',
        'active' => true,
    ])->assertStatus(422);

    expect(ItemType::where('name', 'Orphan Test Item Type')->exists())->toBeFalse();
});
