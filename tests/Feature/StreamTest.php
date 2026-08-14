<?php

use App\Models\Pallet;
use App\Models\Stream;
use App\Models\Warehouse;

test('a stream counts only pallets matching its kind/status/condition criteria', function () {
    $warehouse = Warehouse::create(['name' => 'Test WH', 'address' => '1 Main St', 'city' => 'Town', 'state' => 'CA', 'zip' => '90000']);

    $stream = Stream::create([
        'name' => 'Recycler',
        'warehouse_id' => $warehouse->id,
        'counts_kind' => 'Q',
        'counts_condition' => 'condemned',
        'threshold' => 3,
    ]);

    Pallet::create(['kind' => 'Q', 'status' => 'held', 'condition' => 'condemned', 'datepacked' => now()->toDateString()]);
    Pallet::create(['kind' => 'Q', 'status' => 'held', 'condition' => 'condemned', 'datepacked' => now()->toDateString()]);
    Pallet::create(['kind' => 'Q', 'status' => 'held', 'condition' => 'good', 'datepacked' => now()->toDateString()]); // doesn't match
    Pallet::create(['kind' => 'W', 'status' => 'sealed', 'condition' => 'condemned', 'datepacked' => now()->toDateString()]); // wrong kind

    expect($stream->currentCount())->toBe(2)
        ->and($stream->isOverThreshold())->toBeFalse();

    Pallet::create(['kind' => 'Q', 'status' => 'held', 'condition' => 'condemned', 'datepacked' => now()->toDateString()]);

    expect($stream->currentCount())->toBe(3)
        ->and($stream->isOverThreshold())->toBeTrue();
});

test('a stream with no threshold is never over', function () {
    $warehouse = Warehouse::create(['name' => 'Test WH 2', 'address' => '2 Main St', 'city' => 'Town', 'state' => 'CA', 'zip' => '90000']);
    $stream = Stream::create(['name' => 'Goodwill', 'warehouse_id' => $warehouse->id, 'counts_kind' => 'H']);

    Pallet::create(['kind' => 'H', 'status' => 'filling', 'datepacked' => now()->toDateString()]);

    expect($stream->isOverThreshold())->toBeFalse();
});
