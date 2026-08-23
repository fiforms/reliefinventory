<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;
use App\Models\Transaction;

function outstandingOrdersPartner(): Person
{
    return Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera']);
}

function makeOutstandingOrder(string $status, ?Person $partner = null): Transaction
{
    return Transaction::create([
        'type' => 'order',
        'person_id' => ($partner ?? outstandingOrdersPartner())->id,
        'person_id_user' => userWithPermissions('manage-orders')->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => now()->toDateString(),
    ]);
}

test('the outstanding orders report requires the view-reports permission', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/reports/orders')->assertForbidden();
    $this->actingAs($user)->get('/report/orders.pdf')->assertForbidden();
    $this->actingAs($user)->get('/report/orders.csv')->assertForbidden();
});

test('the outstanding orders report lists every non-Shipped order and excludes Shipped ones', function () {
    $user = userWithPermissions('view-reports');
    $partner = outstandingOrdersPartner();

    $newOrder = makeOutstandingOrder(Transaction::STATUS_NEW_ORDER, $partner);
    $readyToFill = makeOutstandingOrder(Transaction::STATUS_READY_TO_FILL, $partner);
    $filling = makeOutstandingOrder(Transaction::STATUS_FILLING, $partner);
    $filled = makeOutstandingOrder(Transaction::STATUS_FILLED, $partner);
    $shipped = makeOutstandingOrder(Transaction::STATUS_SHIPPED, $partner);

    $records = collect($this->actingAs($user)->getJson('/json/reports/orders')->assertOk()->json('records'));

    expect($records->pluck('id'))
        ->toContain($newOrder->id, $readyToFill->id, $filling->id, $filled->id)
        ->not->toContain($shipped->id);

    $record = $records->firstWhere('id', $newOrder->id);
    expect($record['partner'])->toBe('Pat Rivera')
        ->and($record['status'])->toBe(Transaction::STATUS_NEW_ORDER);
});

test('the outstanding orders CSV downloads for a permitted user', function () {
    $user = userWithPermissions('view-reports');
    makeOutstandingOrder(Transaction::STATUS_NEW_ORDER);

    $response = $this->actingAs($user)->get('/report/orders.csv');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->getContent())
        ->toContain('"Order #",Partner,Status,"Order Date","Needed By",Fulfillment,Lines,"Qty Requested"');
});
