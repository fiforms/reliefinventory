<?php

use App\Models\Pallet;
use App\Models\User;

test('creating pallets assigns plain sequential auto-increment ids with no collision logic', function () {
    $user = User::factory()->create(['role_bitpack' => 4]);

    $first = $this->actingAs($user)->postJson('/json/pallets')->assertCreated()->json('record');
    $second = $this->actingAs($user)->postJson('/json/pallets')->assertCreated()->json('record');

    expect($second['id'])->toBe($first['id'] + 1)
        ->and(Pallet::count())->toBe(2);
});
