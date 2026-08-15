<?php

use App\Models\Item;
use App\Models\Pallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

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

test('pallet lines can carry a content description applied to each created pallet', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/pallets', [
            'count' => 4,
            'content_description' => 'Mixed pallet',
        ])
        ->assertCreated()->json('records');

    expect($records)->toHaveCount(4)
        ->and(collect($records)->pluck('content_description')->unique()->all())->toBe(['Mixed pallet'])
        ->and(Pallet::where('orderdonation_id', $donation->id)->where('content_description', 'Mixed pallet')->count())->toBe(4);
});

test('single-item pallets can be tagged with their item at receiving (expedited-sorting prep)', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    // packagetypes has no timestamp columns, so insert below Eloquent
    $packageTypeId = DB::table('packagetypes')
        ->insertGetId(['plural' => 'Cases', 'singular' => 'Case']);
    $item = Item::create([
        'packagetypes_id' => $packageTypeId,
        'pluscode' => '0001',
        'description' => 'Ketchup, 24ct case',
        'active' => true,
    ]);

    $records = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/pallets', [
            'count' => 2,
            'content_item_id' => $item->id,
        ])
        ->assertCreated()->json('records');

    expect(collect($records)->pluck('content_item_id')->unique()->all())->toBe([$item->id])
        ->and($records[0]['content_item']['description'])->toBe('Ketchup, 24ct case');
});

test('recategorizing an intake re-derives its lifecycle status', function () {
    $user = userWithPermissions('manage-receiving');

    // other -> donation must enter the sorting pipeline as Received
    $logged = Transaction::create([
        'type' => 'donation', 'category' => 'other',
        'status_id' => Transaction::statusId(Transaction::STATUS_LOGGED),
        'order_date' => now()->toDateString(),
    ]);
    $this->actingAs($user)
        ->putJson('/json/receiving/'.$logged->id, ['category' => 'donation'])
        ->assertOk();
    expect($logged->fresh()->status->name)->toBe(Transaction::STATUS_RECEIVED);

    // donation -> supplies must leave the pipeline as Logged
    $received = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $this->actingAs($user)
        ->putJson('/json/receiving/'.$received->id, ['category' => 'supplies'])
        ->assertOk();
    expect($received->fresh()->status->name)->toBe(Transaction::STATUS_LOGGED);
});

test('logged non-donation intakes stay visible in the receiving list', function () {
    $user = userWithPermissions('manage-receiving');
    Transaction::create([
        'type' => 'donation', 'category' => 'equipment',
        'status_id' => Transaction::statusId(Transaction::STATUS_LOGGED),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)->getJson('/json/receiving')->assertOk()->json('records');

    expect(collect($records)->pluck('category'))->toContain('equipment');
});

test('a donation can be flagged donor_identification_pending at intake and defaults to false', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'donor_identification_pending' => true,
    ])->assertCreated()->json('record');
    expect(Transaction::findOrFail($record['id'])->donor_identification_pending)->toBeTrue();

    $unflagged = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
    ])->assertCreated()->json('record');
    expect(Transaction::findOrFail($unflagged['id'])->donor_identification_pending)->toBeFalse();
});

test('a flagged donation stays visible in the receiving list even after it reaches Complete', function () {
    $user = userWithPermissions('manage-receiving');
    $flagged = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
        'order_date' => now()->toDateString(),
        'donor_identification_pending' => true,
    ]);
    $unflaggedComplete = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)->getJson('/json/receiving')->assertOk()->json('records');
    $ids = collect($records)->pluck('id');

    expect($ids)->toContain($flagged->id)
        ->not->toContain($unflaggedComplete->id);
});

test('the donor identification flag can be cleared once a donor is identified', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
        'donor_identification_pending' => true,
    ]);

    $this->actingAs($user)->putJson('/json/receiving/'.$donation->id, [
        'category' => 'donation',
        'donor_identification_pending' => false,
    ])->assertOk();

    expect($donation->fresh()->donor_identification_pending)->toBeFalse();
});
