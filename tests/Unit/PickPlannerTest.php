<?php

use App\Services\PickPlanner;

function planHoldings(array $pool, array $pallets): array
{
    return ['pool' => $pool, 'pallets' => array_map(fn ($p) => $p + [
        'tag' => 'W'.str_pad($p['pallet_id'], 8, '0', STR_PAD_LEFT), 'location' => 'L'.$p['pallet_id'], 'sequence' => $p['pallet_id'] * 10,
        'item_id' => 1, 'item' => 'Water',
    ], $pallets)];
}

function summarize(array $plan): array
{
    return array_map(fn ($s) => $s['kind'] === 'pool' ? "pool:{$s['qty']}" : "{$s['pallet_id']}:{$s['qty']}".($s['needs_open'] ? 's' : 'o'), $plan['steps']);
}

test('whole sealed pallets first, then the open pallet for the remainder', function () {
    $sealed = array_map(fn ($i) => ['pallet_id' => $i, 'status' => 'sealed', 'qty' => 100], range(1, 10));
    $plan = PickPlanner::planFor(planHoldings([], array_merge($sealed, [['pallet_id' => 11, 'status' => 'open', 'qty' => 75]])), 750);

    expect(summarize($plan))->toBe(['1:100s', '2:100s', '3:100s', '4:100s', '5:100s', '6:100s', '7:100s', '11:50o'])
        ->and($plan['short'])->toBe(0);
});

test('the sorting-area pool goes first, then the rest follows the same rule', function () {
    $plan = PickPlanner::planFor(planHoldings(
        [['item_id' => 1, 'item' => 'Water', 'qty' => 3]],
        [['pallet_id' => 1, 'status' => 'open', 'qty' => 2], ['pallet_id' => 2, 'status' => 'open', 'qty' => 50]],
    ), 7);

    expect(summarize($plan))->toBe(['pool:3', '1:2o', '2:2o'])->and($plan['first_sequence'])->toBe(-1);
});

test('open pallets run out, so the oldest sealed pallet is broken for the last of it', function () {
    $plan = PickPlanner::planFor(planHoldings([], [
        ['pallet_id' => 1, 'status' => 'open', 'qty' => 30],
        ['pallet_id' => 2, 'status' => 'sealed', 'qty' => 100],
        ['pallet_id' => 3, 'status' => 'sealed', 'qty' => 100],
    ]), 50);

    expect(summarize($plan))->toBe(['1:30o', '2:20s']);
});

test('a sealed pallet bigger than the remaining need is skipped for whole-pallet picks, smaller later ones still fit', function () {
    $plan = PickPlanner::planFor(planHoldings([], [
        ['pallet_id' => 1, 'status' => 'sealed', 'qty' => 500],
        ['pallet_id' => 2, 'status' => 'sealed', 'qty' => 60],
    ]), 100);

    expect(summarize($plan))->toBe(['1:40s', '2:60s']); // walking order; pallet 2 goes whole, pallet 1 is the one broken
});

test('steps come back in pick-path order and a shortfall is reported', function () {
    $plan = PickPlanner::planFor(planHoldings([], [
        ['pallet_id' => 1, 'status' => 'open', 'qty' => 10, 'sequence' => 900],
        ['pallet_id' => 2, 'status' => 'open', 'qty' => 10, 'sequence' => 100],
        ['pallet_id' => 3, 'status' => 'open', 'qty' => 10, 'sequence' => null],
    ]), 40);

    expect(summarize($plan))->toBe(['2:10o', '1:10o', '3:10o'])->and($plan['short'])->toBe(10)->and($plan['first_sequence'])->toBe(100);
});

test('expiring pallets are picked before undated ones, earliest date first', function () {
    $plan = PickPlanner::planFor(planHoldings([], [
        ['pallet_id' => 1, 'status' => 'sealed', 'qty' => 100],
        ['pallet_id' => 2, 'status' => 'sealed', 'qty' => 100, 'expires_on' => '2027-06-01'],
        ['pallet_id' => 3, 'status' => 'sealed', 'qty' => 100, 'expires_on' => '2027-01-01'],
        ['pallet_id' => 4, 'status' => 'open', 'qty' => 50],
        ['pallet_id' => 5, 'status' => 'open', 'qty' => 50, 'expires_on' => '2027-03-01'],
    ]), 280);

    // the two dated sealed pallets ship whole (the undated 100 no longer fits), then the dated open pallet is emptied
    // before the undated one; steps are listed in pick-path (pallet id) order
    expect(summarize($plan))->toBe(['2:100s', '3:100s', '4:30o', '5:50o']);

    $open = PickPlanner::planFor(planHoldings([], [
        ['pallet_id' => 4, 'status' => 'open', 'qty' => 50],
        ['pallet_id' => 5, 'status' => 'open', 'qty' => 50, 'expires_on' => '2027-03-01'],
    ]), 60);
    expect(summarize($open))->toBe(['4:10o', '5:50o'])->and($open['steps'][1]['expires_on'])->toBe('2027-03-01');
});

test('a sealed pallet that also holds other items is never "whole" for one item — it has to be opened', function () {
    $plan = PickPlanner::planFor(planHoldings([], [
        ['pallet_id' => 1, 'status' => 'sealed', 'qty' => 27, 'pallet_total' => 37], // 27 of ours + 10 of something else
        ['pallet_id' => 2, 'status' => 'open', 'qty' => 9],
    ]), 36);

    expect(summarize($plan))->toBe(['1:27s', '2:9o'])
        ->and($plan['steps'][0]['whole'])->toBeFalse()->and($plan['steps'][0]['needs_open'])->toBeTrue();

    $solo = PickPlanner::planFor(planHoldings([], [['pallet_id' => 1, 'status' => 'sealed', 'qty' => 27, 'pallet_total' => 27]]), 27);
    expect($solo['steps'][0]['whole'])->toBeTrue();
});
