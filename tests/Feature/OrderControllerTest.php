<?php

use App\Models\Status;
use App\Models\User;

beforeEach(function () {
    Status::create(['name' => 'New Order']);
});

test('storing an order sets person_id_user from the authenticated user, ignoring any client-supplied value', function () {
    $actingUser = User::factory()->create();
    $impersonated = User::factory()->create();

    $this->actingAs($actingUser)->postJson('/json/orders', [
        'type' => 'order',
        'person_id_user' => $impersonated->id,
        'status_id' => Status::first()->id,
        'order_date' => now()->toDateString(),
    ])->assertCreated();

    $order = \App\Models\Transaction::where('type', 'order')->first();

    expect($order->person_id_user)->toBe($actingUser->id)
        ->and($order->person_id_user)->not->toBe($impersonated->id);
});
