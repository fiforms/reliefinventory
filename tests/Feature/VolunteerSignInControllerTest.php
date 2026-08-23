<?php

use App\Models\Person;
use App\Models\VolunteerSignIn;

test('signing in creates an open record', function () {
    $user = userWithPermissions('operate-volunteer-kiosk');
    $volunteer = Person::create(['first_name' => 'Val', 'last_name' => 'Unteer', 'is_volunteer' => true]);

    $record = $this->actingAs($user)->postJson('/json/volunteer-sign-ins', [
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
        'agency' => 'ARC',
    ])->assertCreated()->json('record');

    expect($record['status'])->toBe(VolunteerSignIn::STATUS_OPEN)
        ->and($record['agency'])->toBe('ARC')
        ->and(VolunteerSignIn::findOrFail($record['id'])->signed_out_at)->toBeNull();
});

test('a person cannot sign in twice while already signed in', function () {
    $user = userWithPermissions('operate-volunteer-kiosk');
    $volunteer = Person::create(['first_name' => 'Val', 'last_name' => 'Unteer', 'is_volunteer' => true]);
    VolunteerSignIn::create([
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
        'signed_in_at' => now(),
        'status' => VolunteerSignIn::STATUS_OPEN,
    ]);

    $this->actingAs($user)->postJson('/json/volunteer-sign-ins', [
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
    ])->assertStatus(422);
});

test('signing out closes the record and writes an audit trail', function () {
    $user = userWithPermissions('operate-volunteer-kiosk');
    $volunteer = Person::create(['first_name' => 'Val', 'last_name' => 'Unteer', 'is_volunteer' => true]);
    $signIn = VolunteerSignIn::create([
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
        'signed_in_at' => now(),
        'status' => VolunteerSignIn::STATUS_OPEN,
    ]);

    $this->actingAs($user)->postJson("/json/volunteer-sign-ins/{$signIn->id}/sign-out")->assertOk();

    $signIn->refresh();
    expect($signIn->status)->toBe(VolunteerSignIn::STATUS_CLOSED)
        ->and($signIn->signed_out_at)->not->toBeNull()
        ->and($signIn->auditLog()->pluck('field')->all())->toContain('status', 'signed_out_at');
});

test('the roster only lists active volunteers, alphabetically', function () {
    $user = userWithPermissions('operate-volunteer-kiosk');
    Person::create(['first_name' => 'Zed', 'last_name' => 'Last', 'is_volunteer' => true, 'volunteer_active' => true]);
    Person::create(['first_name' => 'Amy', 'last_name' => 'First', 'is_volunteer' => true, 'volunteer_active' => true]);
    Person::create(['first_name' => 'Ida', 'last_name' => 'Inactive', 'is_volunteer' => true, 'volunteer_active' => false]);
    Person::create(['first_name' => 'Nan', 'last_name' => 'NotAVolunteer', 'is_volunteer' => false]);

    $records = $this->actingAs($user)->getJson('/json/volunteer-sign-ins/roster')->assertOk()->json('records');

    expect(collect($records)->pluck('first_name')->all())->toBe(['Amy', 'Zed']);
});

test('search finds a deactivated volunteer that the roster hides', function () {
    $user = userWithPermissions('operate-volunteer-kiosk');
    Person::create(['first_name' => 'Ida', 'last_name' => 'Inactive', 'is_volunteer' => true, 'volunteer_active' => false]);

    $records = $this->actingAs($user)->getJson('/json/volunteer-sign-ins/search?q=Ida')->assertOk()->json('records');

    expect(collect($records)->pluck('first_name'))->toContain('Ida');
});

test('certifying a batch stamps certified_at and certified_by, gated separately from kiosk operation', function () {
    $volunteer = Person::create(['first_name' => 'Val', 'last_name' => 'Unteer', 'is_volunteer' => true]);
    $signIn = VolunteerSignIn::create([
        'person_id' => $volunteer->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
        'signed_in_at' => now(),
        'signed_out_at' => now(),
        'status' => VolunteerSignIn::STATUS_CLOSED,
    ]);

    $kioskOperator = userWithPermissions('operate-volunteer-kiosk');
    $this->actingAs($kioskOperator)
        ->postJson('/json/volunteer-sign-ins/certify', ['ids' => [$signIn->id]])
        ->assertStatus(403);

    $certifier = userWithPermissions('certify-volunteer-hours');
    $this->actingAs($certifier)
        ->postJson('/json/volunteer-sign-ins/certify', ['ids' => [$signIn->id]])
        ->assertOk();

    expect($signIn->fresh()->certified_at)->not->toBeNull()
        ->and($signIn->fresh()->certified_by_person_id)->toBe($certifier->id);
});
