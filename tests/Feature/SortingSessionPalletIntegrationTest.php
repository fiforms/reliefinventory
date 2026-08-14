<?php

use App\Models\Pallet;
use App\Models\User;

test('scanning a receiving pallet auto-advances it from received to sorting', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'received']);

    $tag = 'R'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT);

    $this->actingAs($user)->getJson('/json/sorting-sessions/pallet/'.$tag)
        ->assertOk()
        ->assertJsonPath('record.status', 'sorting');

    expect($pallet->fresh()->status)->toBe('sorting');
});

test('re-scanning a pallet already in sorting does not create a duplicate history row', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'received']);

    $tag = 'R'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT);

    $this->actingAs($user)->getJson('/json/sorting-sessions/pallet/'.$tag)->assertOk();
    $this->actingAs($user)->getJson('/json/sorting-sessions/pallet/'.$tag)->assertOk();

    expect($pallet->statuses()->count())->toBe(2); // received, sorting — not sorting twice
});

test('scanning a non-receiving pallet tag is rejected with a clear message', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);
    $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'sealed']);

    $tag = 'W'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT);

    $this->actingAs($user)->getJson('/json/sorting-sessions/pallet/'.$tag)
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'That tag belongs to a Warehouse pallet, not a Receiving pallet.']);
});

test('scanning an unknown pallet tag 404s', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $this->actingAs($user)->getJson('/json/sorting-sessions/pallet/R99999999')
        ->assertStatus(404);
});
