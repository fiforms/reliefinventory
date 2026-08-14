<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;
use App\Models\Status;
use App\Models\Transaction;

test('creating an order forces New Order status regardless of submitted status_id', function () {
    $user = userWithPermissions('manage-orders');
    $bogusStatus = Status::firstOrCreate(['name' => 'Complete'], ['description' => 'Complete']);

    $this->actingAs($user)->postJson('/json/orders', [
        'type' => 'order',
        'order_date' => now()->toDateString(),
        'status_id' => $bogusStatus->id, // must be ignored
    ])->assertCreated();

    expect(Transaction::where('type', 'order')->latest('id')->first()->status->name)->toBe('New Order');
});

test('updating an order cannot change its status from the form', function () {
    $user = userWithPermissions('manage-orders');
    $order = Transaction::create([
        'type' => 'order',
        'order_date' => now()->toDateString(),
        'status_id' => Transaction::statusId('New Order'),
    ]);
    $otherStatus = Status::firstOrCreate(['name' => 'Complete'], ['description' => 'Complete']);

    $this->actingAs($user)->putJson('/json/orders/'.$order->id, [
        'type' => 'order',
        'order_date' => now()->toDateString(),
        'status_id' => $otherStatus->id, // must be ignored
    ])->assertOk();

    expect($order->fresh()->status->name)->toBe('New Order');
});

test('people are serialized with a combined full_name for combo displays', function () {
    $user = userWithPermissions('manage-people');
    Person::create(['first_name' => 'Dana', 'last_name' => 'Fields', 'organization' => 'Relief Org']);

    $records = $this->actingAs($user)->getJson('/json/people')->assertOk()->json('records');

    expect(collect($records)->pluck('full_name'))->toContain('Dana Fields');
});
