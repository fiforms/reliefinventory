<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Pallet;
use App\Models\Transaction;

function sortingUser()
{
    return userWithPermissions('manage-sorting');
}

function receivingPallet(?int $donationId = null): Pallet
{
    $pallet = Pallet::create([
        'kind' => 'R', 'status' => 'sorting',
        'datepacked' => now()->toDateString(),
        'orderdonation_id' => $donationId,
    ]);
    $pallet->statuses()->create(['status' => 'sorting']);

    return $pallet;
}

function palletTag(Pallet $pallet): string
{
    return 'R'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT);
}

function completedSession(): Transaction
{
    return Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
        'order_date' => now()->toDateString(),
    ]);
}

// ---------------------------------------------------------- pallet empty

test('marking a pallet empty transitions it and records the sorter observation as a note', function () {
    $pallet = receivingPallet();

    $this->actingAs(sortingUser())
        ->postJson('/json/sorting-sessions/pallet/'.palletTag($pallet).'/empty', ['observation' => 'ok'])
        ->assertOk()
        ->assertJsonPath('record.status', 'empty');

    $pallet->refresh();
    expect($pallet->status)->toBe('empty')
        ->and($pallet->condition)->toBe('pending') // QC stays with a supervisor
        ->and($pallet->statuses()->latest('id')->first()->notes)->toBe('Sorter: pallet looks OK');
});

test('a damaged observation is noted but condition still goes to pending, not condemned', function () {
    $pallet = receivingPallet();

    $this->actingAs(sortingUser())
        ->postJson('/json/sorting-sessions/pallet/'.palletTag($pallet).'/empty', ['observation' => 'damaged'])
        ->assertOk();

    $pallet->refresh();
    expect($pallet->condition)->toBe('pending')
        ->and($pallet->statuses()->latest('id')->first()->notes)->toBe('Sorter: set aside - possible damage');
});

test('emptying the last pallet completes the donation via the rollup', function () {
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_SORTING),
        'order_date' => now()->toDateString(),
    ]);
    $first = receivingPallet($donation->id);
    $second = receivingPallet($donation->id);

    $user = sortingUser();
    $this->actingAs($user)
        ->postJson('/json/sorting-sessions/pallet/'.palletTag($first).'/empty')
        ->assertJsonPath('donation_status', Transaction::STATUS_SORTING);

    $this->actingAs($user)
        ->postJson('/json/sorting-sessions/pallet/'.palletTag($second).'/empty')
        ->assertJsonPath('donation_status', Transaction::STATUS_COMPLETE);

    expect($donation->fresh()->status->name)->toBe(Transaction::STATUS_COMPLETE);
});

test('marking an already-empty pallet empty again is idempotent', function () {
    $pallet = receivingPallet();
    $user = sortingUser();

    $this->actingAs($user)->postJson('/json/sorting-sessions/pallet/'.palletTag($pallet).'/empty')->assertOk();
    $this->actingAs($user)->postJson('/json/sorting-sessions/pallet/'.palletTag($pallet).'/empty')->assertOk();

    expect($pallet->statuses()->where('status', 'empty')->count())->toBe(1);
});

test('a non-receiving pallet cannot be marked empty through sorting', function () {
    $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'sealed']);

    $this->actingAs(sortingUser())
        ->postJson('/json/sorting-sessions/pallet/W'.str_pad((string) $pallet->id, 8, '0', STR_PAD_LEFT).'/empty')
        ->assertStatus(422);
});

// ------------------------------------------------- completed sessions read-only

test('lines cannot be added to a completed session', function () {
    $session = completedSession();

    $this->actingAs(sortingUser())
        ->postJson('/json/sorting-sessions/'.$session->id.'/lines', [
            'item_id' => 1, 'qty' => 1, 'disposition' => 'usable',
        ])
        ->assertStatus(409);
});

test('header edits are rejected on a completed session', function () {
    $session = completedSession();

    $this->actingAs(sortingUser())
        ->patchJson('/json/sorting-sessions/'.$session->id, ['comments' => 'sneaky edit'])
        ->assertStatus(409);

    expect($session->fresh()->comments)->not->toBe('sneaky edit');
});

test('a completed session can be reopened, and is editable again afterward', function () {
    $session = completedSession();
    $user = sortingUser();

    $this->actingAs($user)
        ->patchJson('/json/sorting-sessions/'.$session->id, ['completed' => false])
        ->assertOk();

    expect($session->fresh()->status->name)->toBe(Transaction::STATUS_SORTING);

    $this->actingAs($user)
        ->patchJson('/json/sorting-sessions/'.$session->id, ['comments' => 'legit edit'])
        ->assertOk();

    expect($session->fresh()->comments)->toBe('legit edit');
});
