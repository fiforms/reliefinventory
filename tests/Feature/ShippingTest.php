<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Driver;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

function shippingPartner(): Person
{
    return Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera']);
}

function shippingOrder(string $status): Transaction
{
    return Transaction::create([
        'type' => 'order',
        'person_id' => shippingPartner()->id,
        'person_id_user' => User::factory()->create()->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => now()->toDateString(),
    ]);
}

test('shipping endpoints require the manage-orders permission', function () {
    $order = shippingOrder(Transaction::STATUS_FILLED);
    $driver = Driver::create(['name' => 'Sam Carrier']);
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->getJson('/json/shipping')->assertForbidden();
    $this->actingAs($user)->patchJson('/json/shipping/'.$order->id.'/assign', ['driver_id' => $driver->id])->assertForbidden();
    $this->actingAs($user)->patchJson('/json/shipping/'.$order->id.'/mark-shipped')->assertForbidden();
});

test('the queue keeps Filled, Ready to Ship, Shipped, and Delivered in four separate buckets', function () {
    $filled = shippingOrder(Transaction::STATUS_FILLED);
    $readyToShip = shippingOrder(Transaction::STATUS_READY_TO_SHIP);
    $shipped = shippingOrder(Transaction::STATUS_SHIPPED);
    $delivered = shippingOrder(Transaction::STATUS_DELIVERED);
    $newOrder = shippingOrder(Transaction::STATUS_NEW_ORDER);

    $response = $this->actingAs(userWithPermissions('manage-orders'))->getJson('/json/shipping')->assertOk()->json();
    $everyone = [$filled->id, $readyToShip->id, $shipped->id, $delivered->id, $newOrder->id];

    expect(collect($response['filled'])->pluck('id'))->toContain($filled->id)
        ->not->toContain(...array_diff($everyone, [$filled->id]))
        ->and(collect($response['ready_to_ship'])->pluck('id'))->toContain($readyToShip->id)
        ->not->toContain(...array_diff($everyone, [$readyToShip->id]))
        ->and(collect($response['shipped'])->pluck('id'))->toContain($shipped->id)
        ->not->toContain(...array_diff($everyone, [$shipped->id]))
        ->and(collect($response['delivered'])->pluck('id'))->toContain($delivered->id)
        ->not->toContain(...array_diff($everyone, [$delivered->id]));
});

test('assigning a driver to a Filled order moves it to Ready to Ship', function () {
    $order = shippingOrder(Transaction::STATUS_FILLED);
    $driver = Driver::create(['name' => 'Sam Carrier', 'phone' => '555-0001']);

    $response = $this->actingAs(userWithPermissions('manage-orders'))
        ->patchJson('/json/shipping/'.$order->id.'/assign', ['driver_id' => $driver->id])
        ->assertOk();

    $response->assertJsonPath('record.status.name', Transaction::STATUS_READY_TO_SHIP)
        ->assertJsonPath('record.driver_id', $driver->id);
});

test('a driver can be reassigned while still Ready to Ship, without changing status', function () {
    $order = shippingOrder(Transaction::STATUS_READY_TO_SHIP);
    $original = Driver::create(['name' => 'Sam Carrier']);
    $order->update(['driver_id' => $original->id]);
    $replacement = Driver::create(['name' => 'Alex Hauler']);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->patchJson('/json/shipping/'.$order->id.'/assign', ['driver_id' => $replacement->id])
        ->assertOk()
        ->assertJsonPath('record.status.name', Transaction::STATUS_READY_TO_SHIP)
        ->assertJsonPath('record.driver_id', $replacement->id);
});

test('a driver cannot be assigned to an order outside Filled/Ready to Ship', function () {
    $order = shippingOrder(Transaction::STATUS_NEW_ORDER);
    $driver = Driver::create(['name' => 'Sam Carrier']);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->patchJson('/json/shipping/'.$order->id.'/assign', ['driver_id' => $driver->id])
        ->assertStatus(409);
});

test('marking shipped requires Ready to Ship and moves the order to Shipped', function () {
    $order = shippingOrder(Transaction::STATUS_READY_TO_SHIP);
    $user = userWithPermissions('manage-orders');

    $this->actingAs($user)->patchJson('/json/shipping/'.$order->id.'/mark-shipped')
        ->assertOk()->assertJsonPath('record.status.name', Transaction::STATUS_SHIPPED);

    // Already Shipped — cannot mark shipped again.
    $this->actingAs($user)->patchJson('/json/shipping/'.$order->id.'/mark-shipped')->assertStatus(409);
});

test('the signed BOL download 404s until one has been uploaded', function () {
    $order = shippingOrder(Transaction::STATUS_SHIPPED);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->get('/json/shipping/'.$order->id.'/signed-bol')
        ->assertNotFound();
});

test('staff can download the signed BOL once it has been uploaded, and see the order in the delivered bucket', function () {
    $order = shippingOrder(Transaction::STATUS_SHIPPED);
    $path = UploadedFile::fake()->create('signed-bol.pdf', 50, 'application/pdf')
        ->store('signed-bols', 'local');
    $order->update(['signed_bol_path' => $path, 'status_id' => Transaction::statusId(Transaction::STATUS_DELIVERED)]);

    $user = userWithPermissions('manage-orders');

    $this->actingAs($user)->get('/json/shipping/'.$order->id.'/signed-bol')->assertOk();

    $records = collect($this->actingAs($user)->getJson('/json/shipping')->assertOk()->json('delivered'));
    expect($records->pluck('id'))->toContain($order->id);
});

test('approving a Delivered order moves it to Completed and records the reviewer', function () {
    $order = shippingOrder(Transaction::STATUS_DELIVERED);
    $order->update(['signed_bol_path' => UploadedFile::fake()->create('signed-bol.pdf', 10, 'application/pdf')->store('signed-bols', 'local')]);
    $user = userWithPermissions('manage-orders');

    $this->actingAs($user)->postJson('/json/shipping/'.$order->id.'/approve')
        ->assertOk()->assertJsonPath('record.status.name', Transaction::STATUS_COMPLETED);

    $order->refresh();
    expect($order->bol_reviewed_by_person_id)->toBe($user->id);
});

test('approving with a replacement file (the cropped version) swaps the stored signed BOL', function () {
    $order = shippingOrder(Transaction::STATUS_DELIVERED);
    $originalPath = UploadedFile::fake()->create('signed-bol.jpg', 10, 'image/jpeg')->store('signed-bols', 'local');
    $order->update(['signed_bol_path' => $originalPath]);

    $cropped = UploadedFile::fake()->image('cropped.jpg');
    $this->actingAs(userWithPermissions('manage-orders'))
        ->postJson('/json/shipping/'.$order->id.'/approve', ['file' => $cropped])
        ->assertOk();

    $order->refresh();
    expect($order->signed_bol_path)->not->toBe($originalPath);
    expect(Storage::disk('local')->exists($originalPath))->toBeFalse();
    expect(Storage::disk('local')->exists($order->signed_bol_path))->toBeTrue();
});

test('only a Delivered order can be approved', function () {
    $order = shippingOrder(Transaction::STATUS_SHIPPED);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->postJson('/json/shipping/'.$order->id.'/approve')
        ->assertStatus(409);
});

test('rejecting a Delivered order sends it back to Shipped, clears the file, and records the reason', function () {
    $order = shippingOrder(Transaction::STATUS_DELIVERED);
    $path = UploadedFile::fake()->create('signed-bol.jpg', 10, 'image/jpeg')->store('signed-bols', 'local');
    $order->update(['signed_bol_path' => $path]);
    $user = userWithPermissions('manage-orders');

    $this->actingAs($user)->postJson('/json/shipping/'.$order->id.'/reject', ['reason' => 'Signature page is missing.'])
        ->assertOk()->assertJsonPath('record.status.name', Transaction::STATUS_SHIPPED);

    $order->refresh();
    expect($order->signed_bol_path)->toBeNull()
        ->and($order->bol_rejection_reason)->toBe('Signature page is missing.')
        ->and($order->bol_reviewed_by_person_id)->toBe($user->id);
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('only a Delivered order can be rejected', function () {
    $order = shippingOrder(Transaction::STATUS_SHIPPED);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->postJson('/json/shipping/'.$order->id.'/reject')
        ->assertStatus(409);
});

test('a re-uploaded BOL after rejection clears the old rejection reason', function () {
    $driver = Driver::create(['name' => 'Sam Carrier', 'phone' => '555-0200']);
    $order = shippingOrder(Transaction::STATUS_SHIPPED);
    $order->update(['driver_id' => $driver->id, 'bol_rejection_reason' => 'Blurry photo.']);

    $this->actingAs(userWithPermissions('manage-orders'))
        ->postJson('/json/drivers/'.$driver->id.'/set-pin', ['pin' => '97531', 'pin_confirmation' => '97531'])
        ->assertOk();
    Auth::logout();

    $this->postJson('/driver-portal/login', ['phone' => '555-0200', 'pin' => '97531'])->assertOk();
    $this->postJson('/driver-portal/loads/'.$order->id.'/bol', ['file' => UploadedFile::fake()->create('signed-bol.jpg', 10, 'image/jpeg')])
        ->assertOk();

    expect($order->fresh()->bol_rejection_reason)->toBeNull();
});
