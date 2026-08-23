<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

test('the inventory report CSV requires the view-reports permission', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->get('/report/inventory.csv')->assertForbidden();
});

test('the inventory report CSV downloads for a permitted user', function () {
    $user = userWithPermissions('view-reports');

    $response = $this->actingAs($user)->get('/report/inventory.csv');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->getContent())
        ->toContain('"Item #",Name,Status,Category,Unit,"On Hand",Outdated,Trashed,Diverted');
});
