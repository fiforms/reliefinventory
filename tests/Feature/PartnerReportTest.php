<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Transaction;

test('the report and its exports need view-reports', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/reports/partners')->assertForbidden();
    $this->actingAs($user)->get('/report/partners.csv')->assertForbidden();
    $this->actingAs($user)->get('/report/partners.pdf')->assertForbidden();
    $this->actingAs($user)->get('/reports/partners')->assertForbidden();
});

test('partners roll up requests, units, fill rate on finished requests, shipments and county', function () {
    $user = userWithPermissions('view-reports');
    $water = urrItemtype('Bottled Water');
    $diapers = urrItemtype('Diapers');
    $thurston = urrPartner('Thurston', '98501');
    $king = urrPartner('King', '98101');

    // Thurston: one finished (10 asked, 6 filled), one still waiting (20 asked, nothing filled yet).
    urrOrder($thurston, Transaction::STATUS_DELIVERED, [[$water, 10, 6, null]], '2026-10-01');
    urrOrder($thurston, Transaction::STATUS_READY_TO_FILL, [[$water, 20, null, null]], '2026-10-05');
    // King: shipped in full, and one closed with nothing picked.
    urrOrder($king, Transaction::STATUS_SHIPPED, [[$water, 5, 5, null], [$diapers, 5, 5, null]], '2026-10-02');
    urrOrder($king, Transaction::STATUS_CLOSED_UNFULFILLED, [[$diapers, 10, 0, null]], '2026-10-03');

    $report = $this->actingAs($user)->getJson('/json/reports/partners')->assertOk()->json();

    expect($report['totals'])->toMatchArray([
        'partners' => 2, 'requests' => 4, 'requested' => 50, 'filled' => 16, 'shipped' => 2, 'delivered' => 1,
    ])->and($report['totals']['fill_rate'])->toEqual(round(16 / 30 * 100, 1));

    $byName = collect($report['partners'])->keyBy('name');
    $th = $byName['Partner 98501'];
    expect($th)->toMatchArray([
        'county' => 'Thurston', 'zip' => '98501', 'requests' => 2, 'requested' => 30, 'filled' => 6,
        'finished_requests' => 1, 'shipped' => 1, 'delivered' => 1, 'last_request' => '2026-10-05',
    ])->and($th['fill_rate'])->toEqual(60.0)
        ->and($th['orders'])->toHaveCount(2);

    // Ordered by units requested, biggest first; counties rolled up.
    expect($report['partners'][0]['name'])->toBe('Partner 98501')
        ->and(collect($report['by_county'])->firstWhere('county', 'King'))
        ->toMatchArray(['partners' => 1, 'requests' => 2, 'requested' => 20, 'filled' => 10]);
});

test('date and county filters narrow the report', function () {
    $user = userWithPermissions('view-reports');
    $water = urrItemtype('Bottled Water');
    $thurston = urrPartner('Thurston', '98501');
    $king = urrPartner('King', '98101');
    urrOrder($thurston, Transaction::STATUS_COMPLETED, [[$water, 10, 10, null]], '2026-09-01');
    urrOrder($thurston, Transaction::STATUS_COMPLETED, [[$water, 10, 10, null]], '2026-10-10');
    urrOrder($king, Transaction::STATUS_COMPLETED, [[$water, 7, 7, null]], '2026-10-10');

    $dated = $this->actingAs($user)->getJson('/json/reports/partners?from=2026-10-01')->json();
    expect($dated['totals']['requests'])->toBe(2);

    $countyId = $thurston->county_id;
    $county = $this->actingAs($user)->getJson('/json/reports/partners?county_id='.$countyId)->json();
    expect($county['totals']['requests'])->toBe(2)
        ->and($county['partners'])->toHaveCount(1);
});

test('csv and pdf export the same report', function () {
    $user = userWithPermissions('view-reports');
    $water = urrItemtype('Bottled Water');
    urrOrder(urrPartner('Thurston', '98501'), Transaction::STATUS_COMPLETED, [[$water, 10, 10, null]]);

    $csv = $this->actingAs($user)->get('/report/partners.csv')->assertOk();
    expect($csv->getContent())->toContain('Partner 98501');

    $this->actingAs($user)->get('/report/partners.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
});
