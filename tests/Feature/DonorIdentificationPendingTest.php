<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Transaction;

test('an ad-hoc sorting session can flag the donor identification pending', function () {
    $user = userWithPermissions('manage-sorting');
    $session = Transaction::create([
        'type' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_SORTING),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->patchJson('/json/sorting-sessions/'.$session->id, [
        'donor_identification_pending' => true,
    ])->assertOk();

    expect($session->fresh()->donor_identification_pending)->toBeTrue();
});

test('flagging or clearing donor identification is rejected once a session is completed', function () {
    $user = userWithPermissions('manage-sorting');
    $session = Transaction::create([
        'type' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->patchJson('/json/sorting-sessions/'.$session->id, [
        'donor_identification_pending' => true,
    ])->assertStatus(409);

    expect($session->fresh()->donor_identification_pending)->toBeFalse();
});
