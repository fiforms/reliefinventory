<?php

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\PackageType;
use App\Models\Unit;

function moveFixture(): array
{
    $category = Category::create(['name' => 'Move Cat']);
    $unit = Unit::first() ?? Unit::create(['abbreviation' => 'ea', 'name' => 'each-move', 'type' => 'each']);
    $package = PackageType::first() ?? PackageType::create(['singular' => 'Each', 'plural' => 'Eaches']);
    $make = fn ($family, $name) => ItemType::create([
        'family' => $family, 'variant' => '00', 'category_id' => $category->id,
        'unit_id' => $unit->id, 'name' => $name, 'active' => true,
    ]);
    $from = $make('9101', 'From Type');
    $to = $make('9102', 'To Type');
    $item = Item::create([
        'itemtype_id' => $from->id, 'packagetypes_id' => $package->id, 'pluscode' => '0000',
        'size' => 1, 'active' => true, 'description' => 'Movable',
    ]);

    return [$item, $from, $to];
}

test('an administrator can move an item to another ACS #', function () {
    [$item, $from, $to] = moveFixture();
    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->getJson("/json/items/{$item->id}/move-impact")
        ->assertOk()->assertJsonPath('ledger_rows', 0);

    $this->actingAs($admin)->postJson("/json/items/{$item->id}/move", ['itemtype_id' => $to->id])->assertOk();

    expect($item->fresh()->itemtype_id)->toBe($to->id);
});

test('moving an item needs admin-system, and a real different type', function () {
    [$item, $from, $to] = moveFixture();

    $this->actingAs(userWithPermissions('manage-items', 'manage-itemtypes'))
        ->postJson("/json/items/{$item->id}/move", ['itemtype_id' => $to->id])->assertForbidden();
    expect($item->fresh()->itemtype_id)->toBe($from->id);

    $admin = userWithPermissions('admin-system');
    $this->actingAs($admin)->postJson("/json/items/{$item->id}/move", ['itemtype_id' => $from->id])->assertStatus(422);
    $this->actingAs($admin)->postJson("/json/items/{$item->id}/move", ['itemtype_id' => 999999])->assertStatus(422);
});
