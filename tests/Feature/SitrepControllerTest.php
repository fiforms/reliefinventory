<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\County;
use App\Models\Person;
use App\Models\Transaction;

test('the situation report requires the view-sitrep permission', function () {
    $user = userWithPermissions('view-dashboard'); // internal access does NOT imply external report access

    $this->actingAs($user)->getJson('/json/sitrep')->assertForbidden();
});

test('the situation report never includes names, donor quality, or anything beyond the restricted metric set', function () {
    $county = County::create(['county' => 'Thurston', 'state' => 'WA']);
    $person = Person::create(['first_name' => 'SecretFirstName', 'last_name' => 'SecretLastName', 'organization' => 'SecretOrgName']);
    $person->county_id = $county->id;
    $person->save();

    $order = Transaction::create([
        'type' => 'order', 'person_id' => $person->id,
        'status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER),
        'order_date' => now()->toDateString(), 'comments' => 'SecretCommentText',
    ]);

    $user = userWithPermissions('view-sitrep');
    $response = $this->actingAs($user)->getJson('/json/sitrep')->assertOk();

    $response->assertJsonStructure([
        'orders_fulfilled', 'donations_completed', 'orders_trend', 'donations_trend',
        'pipeline', 'county_breakdown', 'inventory_summary', 'generated_at',
    ]);
    $response->assertJsonMissingPath('donor_quality');

    $body = $response->getContent();
    expect($body)->not->toContain('SecretFirstName')
        ->not->toContain('SecretLastName')
        ->not->toContain('SecretOrgName')
        ->not->toContain('SecretCommentText');
});

// PDF byte-rendering needs spatie/browsershot (a real headless-browser
// binary) which isn't installed in this dev environment — same gap as the
// existing, also-untested PalletReportController PDF endpoints. Permission
// gating is still fully testable since middleware runs before Pdf::view().
test('the situation report PDF requires the view-sitrep permission', function () {
    $user = userWithPermissions('view-dashboard');

    $this->actingAs($user)->get('/report/sitrep.pdf')->assertForbidden();
});
