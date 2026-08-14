<?php

use App\Models\Pallet;
use App\Models\User;

test('creating pallets assigns plain sequential auto-increment ids with no collision logic', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $first = $this->actingAs($user)->postJson('/json/pallets', ['kind' => 'R'])->assertCreated()->json('record');
    $second = $this->actingAs($user)->postJson('/json/pallets', ['kind' => 'R'])->assertCreated()->json('record');

    expect($second['id'])->toBe($first['id'] + 1)
        ->and(Pallet::count())->toBe(2);
});

test('a new pallet starts at its kind\'s initial status and logs one history row', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $record = $this->actingAs($user)->postJson('/json/pallets', ['kind' => 'W'])
        ->assertCreated()->json('record');

    $pallet = Pallet::findOrFail($record['id']);

    expect($pallet->status)->toBe('sealed')
        ->and($pallet->statuses()->count())->toBe(1);
});

test('an unrecognized kind is rejected', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $this->actingAs($user)->postJson('/json/pallets', ['kind' => 'X'])
        ->assertStatus(422);
});

test('updating status logs a new history row and does not corrupt the audit trail', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'received']);

    $this->actingAs($user)->putJson('/json/pallets/'.$pallet->id, ['status' => 'sorting'])
        ->assertOk();

    $pallet->refresh();

    expect($pallet->status)->toBe('sorting')
        ->and($pallet->statuses()->count())->toBe(2);
});

test('an invalid status for the pallet\'s kind is rejected without changing anything', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'received']);

    $this->actingAs($user)->putJson('/json/pallets/'.$pallet->id, ['status' => 'shipped'])
        ->assertStatus(422);

    expect($pallet->fresh()->status)->toBe('received')
        ->and($pallet->statuses()->count())->toBe(1);
});

test('marking a pallet missing then restoring round-trips through the controller', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $pallet = Pallet::create(['kind' => 'W', 'status' => 'open', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'open']);

    $this->actingAs($user)->putJson('/json/pallets/'.$pallet->id, ['status' => 'missing'])->assertOk();
    expect($pallet->fresh()->status)->toBe('missing');

    $this->actingAs($user)->putJson('/json/pallets/'.$pallet->id, ['status' => 'open'])->assertOk();
    expect($pallet->fresh()->status)->toBe('open');
});
