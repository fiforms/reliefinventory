<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Status;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

function flowItem(string $name, string $family): Item
{
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $type = ItemType::create([
        'name' => $name, 'family' => $family, 'variant' => '00', 'status' => 'orderable', 'unit_id' => $unitId,
        'category_id' => Category::firstOrCreate(['name' => 'Beverages'])->id,
    ]);
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Case')->value('id')
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Case', 'plural' => 'Cases']);

    return Item::create(['itemtype_id' => $type->id, 'packagetypes_id' => $packagetypeId, 'description' => $name]);
}

function flowTransaction(string $type, array $extra = []): Transaction
{
    return Transaction::create([
        'type' => $type,
        'status_id' => Status::firstOrCreate(['name' => 'Complete'])->id,
        'order_date' => now()->toDateString(),
        ...$extra,
    ]);
}

/** Back-date a ledger row (created_at isn't fillable-friendly through the relation). */
function flowRow(Transaction $t, Item $item, array $attrs, string $at): void
{
    $row = $t->itemLedgers()->create(['item_id' => $item->id, ...$attrs]);
    DB::table('item_ledgers')->where('id', $row->id)->update(['created_at' => $at.' 12:00:00']);
}

test('the report and its exports need view-reports', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/reports/flow')->assertForbidden();
    $this->actingAs($user)->get('/report/flow.csv')->assertForbidden();
    $this->actingAs($user)->get('/report/flow.pdf')->assertForbidden();
    $this->actingAs($user)->get('/reports/flow')->assertForbidden();
});

test('flow splits received, picked, adjustments and losses, and closes at stock on hand', function () {
    $user = userWithPermissions('view-reports');
    $water = flowItem('Bottled Water', '0100');
    $rice = flowItem('Rice', '0200');

    $before = flowTransaction('donation');
    flowRow($before, $water, ['qty_added' => 100, 'disposition' => 'usable'], '2026-09-20');

    $in = flowTransaction('donation');
    flowRow($in, $water, ['qty_added' => 50, 'disposition' => 'usable'], '2026-10-01');
    flowRow($in, $water, ['qty_added' => 8, 'disposition' => 'trashed'], '2026-10-01');
    flowRow($in, $water, ['qty_added' => 3, 'disposition' => 'outdated'], '2026-10-01');
    flowRow($in, $rice, ['qty_added' => 20, 'disposition' => 'usable'], '2026-10-09');
    flowRow($in, $rice, ['qty_added' => 5, 'disposition' => 'diverted'], '2026-10-09');

    $order = flowTransaction('order');
    flowRow($order, $water, ['qty_subtracted' => 30, 'disposition' => 'usable'], '2026-10-09');

    $adjustment = flowTransaction('adjustment', ['adjustment_reason' => 'shrinkage']);
    flowRow($adjustment, $water, ['qty_subtracted' => 4, 'disposition' => 'usable'], '2026-10-10');
    flowRow($adjustment, $rice, ['qty_added' => 2, 'disposition' => 'usable'], '2026-10-10');

    // Outside the range: after it ends.
    flowRow(flowTransaction('donation'), $water, ['qty_added' => 999, 'disposition' => 'usable'], '2026-11-30');

    $report = $this->actingAs($user)
        ->getJson('/json/reports/flow?from=2026-10-01&to=2026-10-15&bucket=week')
        ->assertOk()->json();

    expect($report['totals'])->toMatchArray([
        'opening' => 100, 'received' => 70, 'picked' => 30, 'adjustments' => -2,
        'closing' => 138, 'outdated' => 3, 'trashed' => 8, 'diverted' => 5,
    ]);

    // Weeks start on Monday: 09-28, 10-05, 10-12.
    expect(collect($report['buckets'])->pluck('start')->all())->toBe(['2026-09-28', '2026-10-05', '2026-10-12'])
        ->and($report['buckets'][0])->toMatchArray(['received' => 50, 'picked' => 0, 'on_hand' => 150])
        ->and($report['buckets'][1])->toMatchArray(['received' => 20, 'picked' => 30, 'adjustments' => -2, 'on_hand' => 138])
        ->and($report['buckets'][2])->toMatchArray(['received' => 0, 'on_hand' => 138]);

    $waterRow = collect($report['items'])->firstWhere('name', 'Bottled Water');
    expect($waterRow)->toMatchArray(['opening' => 100, 'received' => 50, 'picked' => 30, 'adjustments' => -4, 'closing' => 116]);

    expect($report['adjustment_reasons'])->toHaveCount(1)
        ->and($report['adjustment_reasons'][0])->toMatchArray(['reason' => 'shrinkage', 'added' => 2, 'removed' => 4]);

    // Closing matches what the Inventory Report calls on hand (range reaches today's data only through 10-15 here).
    $onHand = collect($this->actingAs($user)->getJson('/json/reports/inventory')->json('records'))
        ->firstWhere('name', 'Bottled Water')['on_hand'];
    expect($onHand)->toBe(116 + 999);
});

test('category and item type filters narrow the report', function () {
    $user = userWithPermissions('view-reports');
    $water = flowItem('Bottled Water', '0100');
    $rice = flowItem('Rice', '0200');
    $in = flowTransaction('donation');
    flowRow($in, $water, ['qty_added' => 10, 'disposition' => 'usable'], '2026-10-01');
    flowRow($in, $rice, ['qty_added' => 7, 'disposition' => 'usable'], '2026-10-01');

    $report = $this->actingAs($user)
        ->getJson('/json/reports/flow?from=2026-10-01&to=2026-10-02&bucket=day&itemtype_id='.$rice->itemtype_id)
        ->assertOk()->json();

    expect($report['totals']['received'])->toBe(7)
        ->and($report['items'])->toHaveCount(1)
        ->and($report['buckets'])->toHaveCount(2);
});

test('a range with too many buckets is refused', function () {
    $user = userWithPermissions('view-reports');

    $this->actingAs($user)->getJson('/json/reports/flow?from=2020-01-01&to=2026-10-01&bucket=day')
        ->assertStatus(422);
});

test('csv and pdf export the same report', function () {
    $user = userWithPermissions('view-reports');
    $water = flowItem('Bottled Water', '0100');
    flowRow(flowTransaction('donation'), $water, ['qty_added' => 12, 'disposition' => 'usable'], '2026-10-01');

    $csv = $this->actingAs($user)->get('/report/flow.csv?from=2026-10-01&to=2026-10-07')->assertOk();
    expect($csv->getContent())->toContain('Bottled Water');

    $this->actingAs($user)->get('/report/flow.pdf?from=2026-10-01&to=2026-10-07')
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
