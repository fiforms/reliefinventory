<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\VolunteerSignIn;
use App\Notifications\KioskCheckInAlert;
use Illuminate\Support\Facades\Notification;

function officeUser(): User
{
    $user = User::factory()->create();
    $role = Role::where('name', 'Office')->first() ?? Role::create(['name' => 'Office']);
    $user->roles()->attach($role->id);

    return $user;
}

test('a guest sign-in notifies Office/Administrator/Team Leader users', function () {
    Notification::fake();

    $office = officeUser();
    $kioskUser = userWithPermissions('operate-volunteer-kiosk');
    $guest = Person::create(['first_name' => 'Gary', 'last_name' => 'Guest', 'is_volunteer' => false]);

    $this->actingAs($kioskUser)->postJson('/json/volunteer-sign-ins', [
        'person_id' => $guest->id,
        'category' => VolunteerSignIn::CATEGORY_OTHER,
        'other_category_text' => 'Guest',
    ])->assertCreated();

    Notification::assertSentTo($office, KioskCheckInAlert::class);
});

test("a volunteer's first sign-in notifies, but their next sign-in does not", function () {
    Notification::fake();

    $office = officeUser();
    $kioskUser = userWithPermissions('operate-volunteer-kiosk');
    $volunteer = Person::create(['first_name' => 'Val', 'last_name' => 'Unteer', 'is_volunteer' => true]);

    $this->actingAs($kioskUser)->postJson('/json/volunteer-sign-ins', [
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
    ])->assertCreated();

    Notification::assertSentTo($office, KioskCheckInAlert::class);

    $openSignIn = VolunteerSignIn::where('person_id', $volunteer->id)->first();
    $this->actingAs($kioskUser)->postJson("/json/volunteer-sign-ins/{$openSignIn->id}/sign-out")->assertOk();

    Notification::fake(); // reset the sent-notification log for the second sign-in

    $this->actingAs($kioskUser)->postJson('/json/volunteer-sign-ins', [
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
    ])->assertCreated();

    Notification::assertNothingSent();
});

test('the notification bell lists and marks notifications read', function () {
    $office = officeUser();
    $kioskUser = userWithPermissions('operate-volunteer-kiosk');
    $guest = Person::create(['first_name' => 'Gary', 'last_name' => 'Guest', 'is_volunteer' => false]);

    $this->actingAs($kioskUser)->postJson('/json/volunteer-sign-ins', [
        'person_id' => $guest->id,
        'category' => VolunteerSignIn::CATEGORY_OTHER,
        'other_category_text' => 'Guest',
    ])->assertCreated();

    $records = $this->actingAs($office)->getJson('/json/notifications')->assertOk()->json();
    expect($records['unread_count'])->toBe(1)
        ->and($records['records'])->toHaveCount(1);

    $this->actingAs($office)->postJson('/json/notifications/read-all')->assertOk();

    $after = $this->actingAs($office)->getJson('/json/notifications')->assertOk()->json();
    expect($after['unread_count'])->toBe(0);
});
