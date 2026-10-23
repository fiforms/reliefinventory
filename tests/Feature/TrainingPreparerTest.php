<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Prepare Training loads valid starter work for every station, only with training mode on.

use App\Models\ActivityLog;
use App\Models\DonationOffer;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\Pallet;
use App\Models\TrainingSetting;
use App\Models\Transaction;
use App\Services\PalletStock;
use App\Services\TrainingPreparer;
use Illuminate\Support\Facades\DB;

function trainingCatalog(): array
{
    $types = collect(['Water', 'Canned food', 'Soap'])->map(function ($name, $i) {
        $type = urrItemtype($name);
        $type->forceFill(['family' => sprintf('%04d', 901 + $i), 'variant' => '00', 'active' => true])->save();
        if (! $type->items()->exists()) {
            $packagetypeId = DB::table('packagetypes')->value('id') ?? DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);
            $type->items()->create(['packagetypes_id' => $packagetypeId, 'pluscode' => '0000', 'size' => 1, 'case_qty' => 1, 'active' => true, 'description' => $name]);
        }

        return $type->fresh('items');
    });
    config(['training.items' => $types->map(fn ($t) => ['number' => $t->display_number, 'qty' => 100])->push(['number' => '9999', 'qty' => 5])->all()]);

    return $types->all();
}

function statusCount(string $type, string $status): int
{
    return Transaction::where('type', $type)->where('status_id', Transaction::statusId($status))->count();
}

test('Prepare Training refuses while training mode is off', function () {
    trainingCatalog();
    $this->actingAs(userWithPermissions('manage-training'));

    expect(fn () => app(TrainingPreparer::class)->run())->toThrow(RuntimeException::class);
    expect(Transaction::count())->toBe(0);
});

test('Prepare Training loads every station with consistent stock', function () {
    $types = trainingCatalog();
    TrainingSetting::current()->update(['enabled' => true]);
    $this->actingAs(userWithPermissions('manage-training'));

    $summary = app(TrainingPreparer::class)->run();

    expect($summary['missing_items'])->toBe(['9999'])
        ->and(DonationOffer::where('status', DonationOffer::STATUS_PENDING)->count())->toBe(5)
        ->and(Pallet::where('kind', 'R')->where('status', 'received')->count())->toBe(10)
        ->and(Pallet::where('kind', 'W')->where('status', 'sealed')->count())->toBe(6)
        ->and(statusCount('order', Transaction::STATUS_READY_TO_FILL))->toBe(10)
        ->and(statusCount('order', Transaction::STATUS_FILLED))->toBe(10)
        ->and(statusCount('order', Transaction::STATUS_SHIPPED))->toBe(5)
        ->and(statusCount('order', Transaction::STATUS_DELIVERED))->toBe(5)
        ->and(Driver::whereNotNull('training_team')->count())->toBe(5)
        ->and(Driver::where('training_team', 3)->first()->verifyPin(TrainingPreparer::teamDriverPin(3)))->toBeTrue()
        // Starter data isn't anyone's training work.
        ->and(ActivityLog::count())->toBe(0)
        ->and(TrainingSetting::current()->prepared_summary['team_drivers'])->toHaveCount(5);

    foreach ($types as $type) {
        $itemId = $type->items->first()->id;
        expect(PalletStock::unassigned($itemId))->toBeGreaterThanOrEqual(0)
            ->and(PalletStock::onHand($itemId))->toBe(100 - (int) ItemLedger::where('item_id', $itemId)->sum('qty_subtracted'));
    }

    // Every Ready to Fill line can be filled from what's on hand.
    $need = [];
    foreach (Transaction::where('type', 'order')->where('status_id', Transaction::statusId(Transaction::STATUS_READY_TO_FILL))->with('orderLines')->get() as $order) {
        foreach ($order->orderLines as $line) {
            $need[$line->itemtype_id] = ($need[$line->itemtype_id] ?? 0) + $line->qty_requested;
        }
    }
    foreach ($types as $type) {
        expect(PalletStock::onHand($type->items->first()->id))->toBeGreaterThanOrEqual($need[$type->id] ?? 0);
    }
});
