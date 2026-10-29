<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Prepare Training spreads the scanned training products over the stations, only with training mode on.

use App\Http\Controllers\TrainingAdminController;
use App\Models\ActivityLog;
use App\Models\DonationOffer;
use App\Models\Driver;
use App\Models\ItemLedger;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\PalletStockAssignment;
use App\Models\Transaction;
use App\Services\PalletStock;
use App\Services\TrainingPreparer;
use Illuminate\Support\Facades\DB;

function statusCount(string $type, string $status): int
{
    return Transaction::where('type', $type)->where('status_id', Transaction::statusId($status))->count();
}

/** Every unit in the summary, by item id. */
function placedUnits(array $summary): array
{
    $groups = [$summary['sorting_area'], ...array_column($summary['warehouse_pallets'], 'items'), ...array_column($summary['orders_to_ship'], 'items')];
    $out = [];
    foreach ($groups as $items) {
        foreach ($items as $i) {
            $out[$i['item_id']] = ($out[$i['item_id']] ?? 0) + $i['qty'];
        }
    }

    return $out;
}

test('Prepare Training refuses while training mode is off', function () {
    scannedProducts();
    $this->actingAs(userWithPermissions('manage-training'));

    expect(fn () => app(TrainingPreparer::class)->run())->toThrow(RuntimeException::class);
    expect(Transaction::count())->toBe(0);
});

test('Prepare Training refuses with fewer scanned items than Stations 3–5 need', function () {
    scannedProducts(10, 4); // 40 of 44
    trainingWarehouse();
    $this->actingAs(userWithPermissions('manage-training'));

    expect(fn () => app(TrainingPreparer::class)->run())->toThrow(RuntimeException::class, 'Scan at least');
    expect(Transaction::count())->toBe(0);
});

test('Prepare Training places every scanned unit once in Stations 3–5, extra on the trays; Receiving gets only offers', function () {
    $items = scannedProducts(15, 4); // 60 units: 44 needed, 16 extra
    trainingWarehouse();
    $this->actingAs(userWithPermissions('manage-training'));

    $summary = app(TrainingPreparer::class)->run();
    $plan = TrainingPreparer::plan();

    // Every scanned unit is in exactly one place.
    expect(placedUnits($summary))->toEqualCanonicalizing(collect($items)->mapWithKeys(fn ($i) => [$i->id => 4])->all())
        ->and($summary['products'])->toBe(['units' => 60, 'distinct' => 15])
        ->and($summary['warnings'])->toBe([]);

    $sum = fn (array $groups) => collect($groups)->flatten(1)->sum('qty');
    expect(collect($summary['sorting_area'])->sum('qty'))->toBe($plan['stations'][3])
        ->and($sum(array_column($summary['warehouse_pallets'], 'items')))->toBe($plan['stations'][4] + 16)
        ->and($sum(array_column($summary['orders_to_ship'], 'items')))->toBe($plan['stations'][5])
        ->and($summary['offers'])->toHaveCount(5)
        ->and($summary)->not->toHaveKeys(['receiving', 'pallets_to_sort']);

    // The sorting area is a mix of products, two at a time.
    expect(count($summary['sorting_area']))->toBe(5);

    expect(DonationOffer::where('status', DonationOffer::STATUS_PENDING)->count())->toBe(5)
        ->and(Pallet::where('kind', 'R')->where('status', 'received')->count())->toBe(0)
        ->and(Pallet::where('kind', 'W')->where('status', 'sealed')->count())->toBe(4)
        ->and(statusCount('order', Transaction::STATUS_READY_TO_FILL))->toBe(3)
        ->and(statusCount('order', Transaction::STATUS_FILLED))->toBe(2)
        ->and(statusCount('order', Transaction::STATUS_SHIPPED))->toBe(0)
        ->and(Driver::whereNotNull('training_team')->count())->toBe(5)
        ->and(Driver::where('training_team', 3)->first()->verifyPin(TrainingPreparer::teamDriverPin(3)))->toBeTrue()
        ->and(ActivityLog::count())->toBe(0);

    // Trays go one per table first; the spots are A-1 ... C-4.
    expect(array_column($summary['warehouse_pallets'], 'location'))->toBe(['A-1', 'B-1', 'C-1', 'A-2'])
        ->and($summary['locations'])->toHaveCount(12)
        ->and(Location::where('code', 'C-4')->value('status'))->toBe('active');

    // The system's stock is exactly what is on the sorting area table, the trays and (already picked) the door.
    foreach ($items as $item) {
        $onTables = collect($summary['sorting_area'])->where('item_id', $item->id)->sum('qty');
        $onTrays = collect($summary['warehouse_pallets'])->flatMap(fn ($p) => $p['items'])->where('item_id', $item->id)->sum('qty');
        expect(PalletStock::onHand($item->id))->toBe($onTables + $onTrays)
            ->and(PalletStock::unassigned($item->id))->toBe($onTables)
            ->and((int) PalletStockAssignment::where('item_id', $item->id)->sum('qty'))->toBe($onTrays);
    }

    // Every Ready to Fill line can be picked from the trays.
    $need = [];
    foreach (Transaction::where('type', 'order')->where('status_id', Transaction::statusId(Transaction::STATUS_READY_TO_FILL))->with('orderLines')->get() as $order) {
        expect($order->orderLines->count())->toBeGreaterThanOrEqual(2);
        foreach ($order->orderLines as $line) {
            $need[$line->itemtype_id] = ($need[$line->itemtype_id] ?? 0) + $line->qty_requested;
        }
    }
    foreach ($need as $typeId => $qty) {
        $onTrays = (int) PalletStockAssignment::whereIn('item_id', collect($items)->where('itemtype_id', $typeId)->pluck('id'))->sum('qty');
        expect($onTrays)->toBeGreaterThanOrEqual($qty);
    }

    // Filled orders' fills match their lines.
    foreach ($summary['orders_to_ship'] as $o) {
        foreach ($o['lines'] as $line) {
            expect((int) ItemLedger::where('order_line_id', $line['line_id'])->sum('qty_subtracted'))->toBe($line['qty']);
        }
    }
});

test('Prepare Training warns about too few different products, but runs', function () {
    scannedProducts(4, 11); // 44 units, 4 products
    trainingWarehouse();
    $this->actingAs(userWithPermissions('manage-training'));

    $summary = app(TrainingPreparer::class)->run();

    expect($summary['warnings'])->toHaveCount(1)
        ->and(placedUnits($summary))->toHaveCount(4);
});

test('Prepare Training retires the TR- locations an earlier version made', function () {
    scannedProducts();
    $warehouseId = DB::table('warehouses')->value('id') ?? DB::table('warehouses')->insertGetId(['name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
    $make = function (string $code) use ($warehouseId) {
        $location = new Location(['code' => $code, 'status' => 'active']);
        $location->warehouse_id = $warehouseId;
        $location->save();

        return $location;
    };
    $unused = $make('TR-DOCK');
    $used = $make('TR-A-01');
    $other = $make('TR-ROOM'); // not one of ours
    $pallet = Pallet::create(['kind' => 'W', 'status' => 'sealed', 'container_type' => 'pallet', 'location_id' => $used->id, 'datepacked' => now()->toDateString()]);
    $pallet->statuses()->create(['status' => 'sealed', 'location_id' => $used->id]);
    trainingWarehouse();
    $this->actingAs(userWithPermissions('manage-training'));

    app(TrainingPreparer::class)->run();

    expect(Location::find($unused->id))->toBeNull()
        ->and(Location::find($used->id)->status)->toBe('archived')
        ->and(Location::find($other->id)->status)->toBe('active');
});

test('the setup packet lists the placements and prints a sign per station; spot labels print', function () {
    scannedProducts();
    trainingWarehouse();
    $this->actingAs(userWithPermissions('manage-training'));
    app(TrainingPreparer::class)->run();

    $path = tempnam(sys_get_temp_dir(), 'pkt').'.pdf';
    app(TrainingAdminController::class)->setupPacketBuilder()->save($path);
    expect(pdfText($path))->toContain('offers waiting for arrival')->toContain('Spot A-1')->toContain('Station 2')->toContain('Sorting')
        ->toContain('Product 1')->not->toContain('TR-');
    unlink($path);

    $path = tempnam(sys_get_temp_dir(), 'spt').'.pdf';
    app(TrainingAdminController::class)->spotLabelsBuilder()->save($path);
    expect(pdfInfo($path))->toMatch('/Pages:\s+12\b/')
        ->and(pdfText($path))->toContain('A-1')->toContain('C-4');
    unlink($path);
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');
