<?php

use App\Models\Pallet;
use App\Models\Transaction;

function donationRollupTestDonation(): Transaction
{
    return Transaction::create([
        'type' => 'donation',
        'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
}

function donationRollupTestPallet(Transaction $donation, string $status = 'received'): Pallet
{
    $pallet = Pallet::create([
        'kind' => 'R',
        'status' => $status,
        'orderdonation_id' => $donation->id,
        'datepacked' => now()->toDateString(),
    ]);
    $pallet->statuses()->create(['status' => $status]);

    return $pallet;
}

test('the first pallet to leave received rolls the donation to sorting', function () {
    $donation = donationRollupTestDonation();
    $p1 = donationRollupTestPallet($donation);
    donationRollupTestPallet($donation);

    $p1->transitionTo('sorting');

    expect($donation->fresh()->status->name)->toBe(Transaction::STATUS_SORTING);
});

test('the donation stays in sorting until every linked pallet is empty', function () {
    $donation = donationRollupTestDonation();
    $p1 = donationRollupTestPallet($donation);
    $p2 = donationRollupTestPallet($donation);

    $p1->transitionTo('sorting');
    $p1->transitionTo('empty');

    expect($donation->fresh()->status->name)->toBe(Transaction::STATUS_SORTING);

    $p2->transitionTo('sorting');
    $p2->transitionTo('empty');

    expect($donation->fresh()->status->name)->toBe(Transaction::STATUS_COMPLETE);
});

test('this stays correct even when the same pallet instance drives multiple transitions', function () {
    // Regression test: the rollup previously used a cached belongsTo
    // relation that went stale across repeated calls on one instance.
    $donation = donationRollupTestDonation();
    $p1 = donationRollupTestPallet($donation);
    $p2 = donationRollupTestPallet($donation);

    $p2->transitionTo('sorting');
    $p2->transitionTo('empty');
    $p1->transitionTo('sorting');
    $p1->transitionTo('empty');

    expect($donation->fresh()->status->name)->toBe(Transaction::STATUS_COMPLETE);
});

test('a donation with no linked pallets never auto-rolls', function () {
    $donation = donationRollupTestDonation();

    expect($donation->fresh()->status->name)->toBe(Transaction::STATUS_RECEIVED);
});

test('a pallet unrelated to any donation does not error', function () {
    $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'datepacked' => now()->toDateString()]);

    $pallet->transitionTo('open');

    expect($pallet->fresh()->status)->toBe('open');
});
