<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

test('the inventory report PDF requires the view-reports permission', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->get('/report/inventory.pdf')->assertForbidden();
});
