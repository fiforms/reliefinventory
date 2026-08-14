<?php

use App\Models\Transaction;

test('starting a session with no donation_id still creates a fresh one (untagged/walk-in fallback)', function () {
    $user = userWithPermissions('manage-sorting');

    $record = $this->actingAs($user)->postJson('/json/sorting-sessions')
        ->assertCreated()->json('record');

    expect($record['status']['name'])->toBe(Transaction::STATUS_SORTING);
});

test('starting a session with a donation_id picks up the existing Receiving-created donation', function () {
    $user = userWithPermissions('manage-sorting');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $record = $this->actingAs($user)->postJson('/json/sorting-sessions', ['donation_id' => $donation->id])
        ->assertCreated()->json('record');

    expect($record['id'])->toBe($donation->id)
        ->and(Transaction::count())->toBe(1); // no duplicate created
});

test('received donations show up as receivable, not mixed into open or recent', function () {
    $user = userWithPermissions('manage-sorting');
    Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($user)->getJson('/json/sorting-sessions')->assertOk();

    expect($response->json('receivable'))->toHaveCount(1)
        ->and($response->json('open'))->toHaveCount(0)
        ->and($response->json('recent'))->toHaveCount(0);
});
