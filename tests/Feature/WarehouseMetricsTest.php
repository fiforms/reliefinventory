<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\County;
use App\Models\Person;
use App\Models\Transaction;
use App\Services\WarehouseMetrics;
use Illuminate\Support\Carbon;

function makeOrderAt(string $status, Carbon $statusChangedAt, ?Person $person = null): Transaction
{
    $order = Transaction::create([
        'type' => 'order',
        'person_id' => $person?->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => $statusChangedAt->toDateString(),
    ]);
    $order->forceFill(['status_changed_at' => $statusChangedAt])->save();

    return $order;
}

function makeDonationAt(string $status, Carbon $statusChangedAt): Transaction
{
    $donation = Transaction::create([
        'type' => 'donation',
        'status_id' => Transaction::statusId($status),
        'order_date' => $statusChangedAt->toDateString(),
    ]);
    $donation->forceFill(['status_changed_at' => $statusChangedAt])->save();

    return $donation;
}

test('orders fulfilled counts bucket by status_changed_at and only count Filled/Shipped', function () {
    makeOrderAt(Transaction::STATUS_FILLED, now()->subHours(2));
    makeOrderAt(Transaction::STATUS_SHIPPED, now()->subDays(5));
    makeOrderAt(Transaction::STATUS_SHIPPED, now()->subDays(20));
    makeOrderAt(Transaction::STATUS_NEW_ORDER, now()->subHours(1)); // not fulfilled - excluded regardless of recency

    $counts = (new WarehouseMetrics)->ordersFulfilledCounts();

    expect($counts['today'])->toBe(1)
        ->and($counts['last_7_days'])->toBe(2)
        ->and($counts['last_30_days'])->toBe(3)
        ->and($counts['all_time'])->toBe(3);
});

test('donations completed counts only include Complete status', function () {
    makeDonationAt(Transaction::STATUS_COMPLETE, now()->subHours(3));
    makeDonationAt(Transaction::STATUS_SORTING, now()->subHours(1)); // in progress - excluded

    $counts = (new WarehouseMetrics)->donationsCompletedCounts();

    expect($counts['today'])->toBe(1)
        ->and($counts['all_time'])->toBe(1);
});

test('trend direction is up when the current window exceeds the prior window beyond the static threshold', function () {
    // current 7-day window: 3 fulfilled; prior 7-day window: 1 fulfilled
    makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(1));
    makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(2));
    makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(3));
    makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(10));

    $trend = (new WarehouseMetrics)->ordersTrend(7);

    expect($trend['current'])->toBe(3)
        ->and($trend['prior'])->toBe(1)
        ->and($trend['direction'])->toBe('up');
});

test('trend direction is static when the prior window was zero and the current window is also zero', function () {
    $trend = (new WarehouseMetrics)->ordersTrend(7);

    expect($trend['current'])->toBe(0)
        ->and($trend['prior'])->toBe(0)
        ->and($trend['direction'])->toBe('static');
});

test('trend direction is up (undefined percent) when the prior window was zero but current is not', function () {
    makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(1));

    $trend = (new WarehouseMetrics)->ordersTrend(7);

    expect($trend['current'])->toBe(1)
        ->and($trend['prior'])->toBe(0)
        ->and($trend['direction'])->toBe('up')
        ->and($trend['percent'])->toBeNull();
});

test('trend direction is static when change is within the threshold band', function () {
    // 21 vs 20 = 5% change, right at the static/up boundary (threshold is "< 5%", so 5% itself is NOT static)
    // use a clearly-under-threshold pair instead: 100 vs 98 = ~2%
    for ($i = 0; $i < 100; $i++) {
        makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(1));
    }
    for ($i = 0; $i < 98; $i++) {
        makeOrderAt(Transaction::STATUS_FILLED, now()->subDays(8));
    }

    $trend = (new WarehouseMetrics)->ordersTrend(7);

    expect($trend['direction'])->toBe('static');
});

test('pipeline counts group by status and report zero for statuses with no rows', function () {
    makeDonationAt(Transaction::STATUS_RECEIVED, now());
    makeDonationAt(Transaction::STATUS_RECEIVED, now());
    makeOrderAt(Transaction::STATUS_NEW_ORDER, now());

    $counts = (new WarehouseMetrics)->pipelineCounts();

    expect($counts['donations'][Transaction::STATUS_RECEIVED])->toBe(2)
        ->and($counts['donations'][Transaction::STATUS_SORTING])->toBe(0)
        ->and($counts['orders'][Transaction::STATUS_NEW_ORDER])->toBe(1)
        ->and($counts['orders'][Transaction::STATUS_SHIPPED])->toBe(0);
});

test('order county breakdown groups by customer county without exposing names', function () {
    $county = County::create(['county' => 'Thurston', 'state' => 'WA']);
    // county_id isn't mass-assignable on Person (not in $fillable), so set it directly
    $inCounty = Person::create(['first_name' => 'A', 'last_name' => 'B']);
    $inCounty->county_id = $county->id;
    $inCounty->save();
    $noCounty = Person::create(['first_name' => 'C', 'last_name' => 'D']);

    makeOrderAt(Transaction::STATUS_NEW_ORDER, now(), $inCounty);
    makeOrderAt(Transaction::STATUS_NEW_ORDER, now(), $inCounty);
    makeOrderAt(Transaction::STATUS_NEW_ORDER, now(), $noCounty);

    $breakdown = collect((new WarehouseMetrics)->orderCountyBreakdown());

    expect($breakdown->firstWhere('county', 'Thurston')['count'])->toBe(2)
        ->and($breakdown->firstWhere('county', 'Unspecified')['count'])->toBe(1)
        ->and(json_encode($breakdown->all()))->not->toContain('A')->not->toContain('B');
});
