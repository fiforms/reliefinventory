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

test('the Receiving dashboard lists open donations and close-out candidates separately', function () {
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

    expect($response->json('records'))->toHaveCount(1)
        ->and($response->json('close_out_candidates'))->toHaveCount(1);
});
