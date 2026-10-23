<?php

use App\Models\Location;
use App\Models\UseModel;
use App\Models\Warehouse;

test('adding a location puts it in the warehouse', function () {
    $this->actingAs(userWithPermissions('manage-locations', 'general-access'));
    $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Test WH']);
    $use = UseModel::first() ?? UseModel::create(['use' => 'Storage']);

    $this->postJson('/json/locations', ['use_id' => $use->id, 'code' => 'NEW-1', 'status' => 'active'])
        ->assertCreated();

    expect(Location::where('code', 'NEW-1')->value('warehouse_id'))->toBe($warehouse->id);
});
