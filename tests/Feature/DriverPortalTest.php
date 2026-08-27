<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Driver;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

function driverPortalPartner(): Person
{
    return Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera']);
}

function pinnedDriver(string $pin = '13579'): Driver
{
    $driver = Driver::create(['name' => 'Sam Carrier', 'phone' => '555-0100']);

    test()->actingAs(userWithPermissions('manage-orders'))
        ->postJson('/json/drivers/'.$driver->id.'/set-pin', ['pin' => $pin, 'pin_confirmation' => $pin])
        ->assertOk();
    Auth::logout();

    return $driver->fresh();
}

function driverOrder(string $status, ?Driver $driver = null): Transaction
{
    return Transaction::create([
        'type' => 'order',
        'person_id' => driverPortalPartner()->id,
        'person_id_user' => User::factory()->create()->id,
        'driver_id' => $driver?->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => now()->toDateString(),
    ]);
}

test('a driver can set their PIN via staff and log in with phone + PIN', function () {
    $driver = pinnedDriver('24689');

    $this->postJson('/driver-portal/login', ['phone' => '555-0100', 'pin' => '24689'])
        ->assertOk()->assertJsonPath('driverName', 'Sam Carrier');
});

test('a wrong PIN is rejected', function () {
    pinnedDriver('24689');

    $this->postJson('/driver-portal/login', ['phone' => '555-0100', 'pin' => '11111'])
        ->assertStatus(422);
});

test('phone matching is digits-only, so formatting differences between staff entry and driver entry still match', function () {
    pinnedDriver('24689'); // stored as "555-0100"

    $this->postJson('/driver-portal/login', ['phone' => '5550100', 'pin' => '24689'])
        ->assertOk()->assertJsonPath('driverName', 'Sam Carrier');
});

test('a phone number that matches no driver is rejected', function () {
    pinnedDriver('24689');

    $this->postJson('/driver-portal/login', ['phone' => '999-9999', 'pin' => '24689'])
        ->assertStatus(422);
});

test('loads() requires either a driver session or a staff manage-orders viewer', function () {
    $this->getJson('/driver-portal/loads')->assertStatus(401);
});

test('a signed-in driver sees only their own assigned loads', function () {
    $driver = pinnedDriver();
    $other = Driver::create(['name' => 'Alex Hauler']);

    $mine = driverOrder(Transaction::STATUS_READY_TO_SHIP, $driver);
    $notMine = driverOrder(Transaction::STATUS_READY_TO_SHIP, $other);

    $this->postJson('/driver-portal/login', ['phone' => '555-0100', 'pin' => '13579'])->assertOk();

    $response = $this->getJson('/driver-portal/loads')->assertOk()->json();
    expect(collect($response['current'])->pluck('id'))->toContain($mine->id)->not->toContain($notMine->id);
});

test('a staff viewer with manage-orders sees every current load without a driver session', function () {
    $driverA = pinnedDriver();
    $driverB = Driver::create(['name' => 'Alex Hauler']);
    $orderA = driverOrder(Transaction::STATUS_READY_TO_SHIP, $driverA);
    $orderB = driverOrder(Transaction::STATUS_SHIPPED, $driverB);

    $response = $this->actingAs(userWithPermissions('manage-orders'))
        ->getJson('/driver-portal/loads')->assertOk()->json();

    expect(collect($response['current'])->pluck('id'))->toContain($orderA->id, $orderB->id);
});

test('a driver uploading their signed BOL moves the order to Delivered', function () {
    $driver = pinnedDriver();
    $order = driverOrder(Transaction::STATUS_SHIPPED, $driver);

    $this->postJson('/driver-portal/login', ['phone' => '555-0100', 'pin' => '13579'])->assertOk();

    $file = UploadedFile::fake()->create('signed-bol.pdf', 100, 'application/pdf');
    $response = $this->postJson('/driver-portal/loads/'.$order->id.'/bol', ['file' => $file])->assertOk();

    $response->assertJsonPath('record.status.name', Transaction::STATUS_DELIVERED);
    expect($order->fresh()->signed_bol_path)->not->toBeNull();
});

test('a driver cannot upload a BOL for a load assigned to someone else', function () {
    $driver = pinnedDriver();
    $other = Driver::create(['name' => 'Alex Hauler']);
    $order = driverOrder(Transaction::STATUS_SHIPPED, $other);

    $this->postJson('/driver-portal/login', ['phone' => '555-0100', 'pin' => '13579'])->assertOk();

    $file = UploadedFile::fake()->create('signed-bol.pdf', 100, 'application/pdf');
    $this->postJson('/driver-portal/loads/'.$order->id.'/bol', ['file' => $file])->assertForbidden();
});

test('a BOL cannot be uploaded for a load that is not Ready to Ship or Shipped', function () {
    $driver = pinnedDriver();
    $order = driverOrder(Transaction::STATUS_FILLED, $driver);

    $this->postJson('/driver-portal/login', ['phone' => '555-0100', 'pin' => '13579'])->assertOk();

    $file = UploadedFile::fake()->create('signed-bol.pdf', 100, 'application/pdf');
    $this->postJson('/driver-portal/loads/'.$order->id.'/bol', ['file' => $file])->assertStatus(409);
});

test('staff can upload a BOL on a driver\'s behalf', function () {
    $driver = pinnedDriver();
    $order = driverOrder(Transaction::STATUS_SHIPPED, $driver);

    $file = UploadedFile::fake()->create('signed-bol.pdf', 100, 'application/pdf');
    $this->actingAs(userWithPermissions('manage-orders'))
        ->postJson('/driver-portal/loads/'.$order->id.'/bol', ['file' => $file])
        ->assertOk()->assertJsonPath('record.status.name', Transaction::STATUS_DELIVERED);
});
