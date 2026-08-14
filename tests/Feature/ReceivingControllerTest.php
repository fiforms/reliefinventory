<?php

use App\Models\Pallet;
use App\Models\Transaction;

test('recording a donation intake creates it in received status', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'container_count' => 8,
        'manifest' => 'Assorted canned goods, roughly 8 pallets.',
        'manifest_weight_lbs' => 12000,
    ])->assertCreated()->json('record');

    $donation = Transaction::findOrFail($record['id']);

    expect($donation->status->name)->toBe(Transaction::STATUS_RECEIVED)
        ->and($donation->category)->toBe('donation')
        ->and((float) $donation->manifest_weight_lbs)->toBe(12000.0);
});

test('a non-donation category is logged but never enters the donation pipeline', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'equipment',
        'manifest' => 'A pallet jack, donated.',
    ])->assertCreated()->json('record');

    $donation = Transaction::findOrFail($record['id']);

    expect($donation->status->name)->toBe(Transaction::STATUS_LOGGED);
});

test('creating pallets for a donation links them and puts them in received status', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/pallets', ['count' => 3])
        ->assertCreated()->json('records');

    expect($records)->toHaveCount(3)
        ->and(Pallet::where('orderdonation_id', $donation->id)->count())->toBe(3)
        ->and(Pallet::where('orderdonation_id', $donation->id)->where('status', 'received')->count())->toBe(3);
});

test('close-out fires only when exactly one pallet remains, already in sorting', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $p1 = Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);
    $p1->statuses()->create(['status' => 'received']);
    $p2 = Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);
    $p2->statuses()->create(['status' => 'received']);

    // Not a candidate yet: two pallets still open.
    $this->actingAs($user)->postJson('/json/receiving/'.$donation->id.'/close-out')->assertStatus(422);

    $p1->transitionTo('sorting');
    $p1->transitionTo('empty');
    $p2->transitionTo('sorting'); // exactly one non-empty, already in sorting

    $this->actingAs($user)->postJson('/json/receiving/'.$donation->id.'/close-out')->assertOk();

    expect($p2->fresh()->status)->toBe('empty')
        ->and($donation->fresh()->status->name)->toBe(Transaction::STATUS_COMPLETE);
});

test('the Receiving dashboard flags close-out candidates on each record', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $p1 = Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);
    $p1->statuses()->create(['status' => 'received']);
    $p1->transitionTo('sorting');

    $response = $this->actingAs($user)->getJson('/json/receiving')->assertOk();
    $records = $response->json('records');

    expect($records)->toHaveCount(1)
        ->and($records[0]['is_close_out_candidate'])->toBeTrue();
});

test('updating an intake edits its fields', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
        'manifest' => 'Original manifest text.',
    ]);

    $this->actingAs($user)->putJson('/json/receiving/'.$donation->id, [
        'category' => 'donation',
        'container_count' => 12,
        'manifest' => 'Corrected: actually 12 pallets.',
    ])->assertOk();

    expect($donation->fresh()->manifest)->toBe('Corrected: actually 12 pallets.')
        ->and($donation->fresh()->container_count)->toBe(12);
});

test('category cannot change once pallets exist for the intake', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);

    $this->actingAs($user)->putJson('/json/receiving/'.$donation->id, [
        'category' => 'equipment',
    ])->assertStatus(422);

    expect($donation->fresh()->category)->toBe('donation');
});

test('an intake with no pallets can be deleted', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->deleteJson('/json/receiving/'.$donation->id)->assertOk();

    expect(Transaction::find($donation->id))->toBeNull();
});

test('an intake with pallets already created cannot be deleted', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);

    $this->actingAs($user)->deleteJson('/json/receiving/'.$donation->id)->assertStatus(422);

    expect(Transaction::find($donation->id))->not->toBeNull();
});
