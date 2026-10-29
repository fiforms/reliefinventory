<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\UnfulfilledRequestsReportController;
use App\Models\Transaction;

test('the report and its exports need view-reports', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/reports/unfulfilled')->assertForbidden();
    $this->actingAs($user)->get('/report/unfulfilled.csv')->assertForbidden();
    $this->actingAs($user)->get('/report/unfulfilled.pdf')->assertForbidden();
    $this->actingAs($user)->get('/reports/unfulfilled')->assertForbidden();
});

test('only short lines on finished orders count, split by why they came up short', function () {
    $water = urrItemtype('Bottled Water');
    $diapers = urrItemtype('Diapers');
    $thurston = urrPartner('Thurston', '98501');

    // Filled order: water asked 50, approved 40, picked 30 → 20 short (10 cut, 10 at picking).
    // Diapers fully filled → not on the report at all.
    urrOrder($thurston, Transaction::STATUS_FILLED, [[$water, 50, 30, 40], [$diapers, 10, 10, null]]);
    // Nothing picked at all → Closed - Unfulfilled, every unit short at picking.
    urrOrder($thurston, Transaction::STATUS_CLOSED_UNFULFILLED, [[$diapers, 8, 0, null]]);
    // Not finished yet — must not appear even though nothing is filled.
    urrOrder($thurston, Transaction::STATUS_READY_TO_FILL, [[$water, 99, null, null]]);
    urrOrder($thurston, Transaction::STATUS_FILLING, [[$water, 99, null, null]]);

    $report = $this->actingAs(userWithPermissions('view-reports'))
        ->getJson('/json/reports/unfulfilled')->assertOk()->json();

    $items = collect($report['items'])->keyBy('name');
    expect($items->keys()->all())->toBe(['Bottled Water', 'Diapers'])
        ->and($items['Bottled Water'])->toMatchArray([
            'requests' => 1, 'requested' => 50, 'filled' => 30, 'not_filled' => 20,
            'cut_at_allocation' => 10, 'short_at_picking' => 10, 'unit' => $water->unit->abbreviation, 'category' => 'Beverages',
        ])
        ->and($items['Diapers'])->toMatchArray([
            'requests' => 1, 'requested' => 8, 'filled' => 0, 'not_filled' => 8,
            'cut_at_allocation' => 0, 'short_at_picking' => 8,
        ])
        ->and($report['totals'])->toMatchArray(['item_types' => 2, 'requests' => 2, 'not_filled' => 28]);
});

test('items break down by county and zip, and places roll up across items', function () {
    $water = urrItemtype('Bottled Water');
    $a = urrPartner('Thurston', '98501');
    $b = urrPartner('Thurston', '98502');
    $c = urrPartner(null, '');

    urrOrder($a, Transaction::STATUS_SHIPPED, [[$water, 10, 4, null]]);
    urrOrder($a, Transaction::STATUS_COMPLETED, [[$water, 5, 0, null]]);
    urrOrder($b, Transaction::STATUS_DELIVERED, [[$water, 20, 0, 0]]);
    urrOrder($c, Transaction::STATUS_DELIVERED_AWAITING_BOL, [[$water, 3, 1, null]]);

    $report = app(UnfulfilledRequestsReportController::class)->buildReport();
    $locations = collect($report['items'][0]['locations'])->keyBy(fn ($l) => $l['county'].'|'.$l['zip']);

    expect($locations['Thurston|98502'])->toMatchArray(['requests' => 1, 'not_filled' => 20, 'cut_at_allocation' => 20])
        ->and($locations['Thurston|98501'])->toMatchArray(['requests' => 2, 'requested' => 15, 'filled' => 4, 'not_filled' => 11])
        ->and($locations['Unspecified|Unspecified'])->toMatchArray(['not_filled' => 2]);

    $byCounty = collect($report['by_county'])->keyBy('county');
    expect($byCounty['Thurston'])->toMatchArray(['requests' => 3, 'item_types' => 1, 'not_filled' => 31])
        ->and($byCounty['Unspecified']['not_filled'])->toBe(2)
        ->and(collect($report['by_zip'])->pluck('zip')->all())->toBe(['98502', '98501', 'Unspecified']);
});

test('the order-date range filters the screen, CSV and print params', function () {
    $water = urrItemtype('Bottled Water');
    $p = urrPartner('Thurston', '98501');
    urrOrder($p, Transaction::STATUS_FILLED, [[$water, 10, 0, null]], '2026-09-15');
    urrOrder($p, Transaction::STATUS_FILLED, [[$water, 7, 0, null]], '2026-10-05');
    $user = userWithPermissions('view-reports');

    $this->actingAs($user)->getJson('/json/reports/unfulfilled?from=2026-10-01')
        ->assertOk()->assertJsonPath('totals.not_filled', 7)->assertJsonPath('filters.from', '2026-10-01');
    $this->actingAs($user)->getJson('/json/reports/unfulfilled?to=2026-09-30')
        ->assertOk()->assertJsonPath('totals.not_filled', 10);
    $this->actingAs($user)->getJson('/json/reports/unfulfilled?from=2026-10-05&to=2026-10-01')
        ->assertUnprocessable();

    $csv = $this->actingAs($user)->get('/report/unfulfilled.csv?from=2026-10-01')->assertOk()->getContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    expect($rows[0])->toBe(['Item #', 'Item', 'Category', 'Unit', 'County', 'Zip', 'Requests', 'Qty Requested',
        'Qty Filled', 'Qty Not Filled', 'Cut at Allocation', 'Short at Picking'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1][4])->toBe('Thurston')
        ->and($rows[1][9])->toBe('7');
});

test('the PDF renders as weasyprint letter pages with the item and its place', function () {
    $water = urrItemtype('Bottled Water');
    urrOrder(urrPartner('Thurston', '98501'), Transaction::STATUS_FILLED, [[$water, 50, 30, 40]]);

    $path = tempnam(sys_get_temp_dir(), 'urr').'.pdf';
    app(UnfulfilledRequestsReportController::class)->pdfBuilder()->save($path);

    expect(pdfInfo($path))->toContain('WeasyPrint')->toMatch('/Page size:\s+612(\.\d+)? x 792(\.\d+)? pts/');
    $text = pdfText($path);
    foreach (['Unfulfilled Requests Report', 'Not Filled by County', 'Not Filled by Zip', 'Bottled Water', 'Thurston', '98501'] as $expected) {
        expect($text)->toContain($expected);
    }
})->skip(fn () => pdfToolsMissing(), 'poppler-utils not installed');
