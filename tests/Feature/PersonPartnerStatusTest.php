<?php

use App\Models\Person;

test('an untracked partner status can move to approved or denied but not directly to blocked', function () {
    $person = Person::create(['organization' => 'Acme Relief', 'is_organization' => true]);
    $admin = userWithPermissions('manage-people');

    $this->actingAs($admin)->postJson("/json/people/{$person->id}/partner-status", [
        'to_status' => 'blocked',
    ])->assertStatus(422);

    $this->actingAs($admin)->postJson("/json/people/{$person->id}/partner-status", [
        'to_status' => 'approved',
        'notes' => 'Looks legitimate.',
    ])->assertOk();

    $person->refresh();
    expect($person->partner_status)->toBe(Person::PARTNER_STATUS_APPROVED)
        ->and($person->partnerStatusLogs)->toHaveCount(1)
        ->and($person->partnerStatusLogs->first()->from_status)->toBeNull()
        ->and($person->partnerStatusLogs->first()->to_status)->toBe('approved')
        ->and($person->partnerStatusLogs->first()->notes)->toBe('Looks legitimate.');
});

test('an approved partner can be blocked, and a blocked partner can be unblocked back to approved', function () {
    $person = Person::create(['organization' => 'Acme Relief', 'is_organization' => true]);
    $person->transitionPartnerStatus(Person::PARTNER_STATUS_APPROVED, null);
    $admin = userWithPermissions('manage-people');

    $this->actingAs($admin)->postJson("/json/people/{$person->id}/partner-status", [
        'to_status' => 'blocked',
        'notes' => 'Reported misuse of donated goods.',
    ])->assertOk();
    expect($person->fresh()->partner_status)->toBe('blocked');

    // A blocked partner can't be denied-then-approved in one step, but can
    // move straight back to approved (unblock).
    $this->actingAs($admin)->postJson("/json/people/{$person->id}/partner-status", [
        'to_status' => 'approved',
    ])->assertOk();
    expect($person->fresh()->partner_status)->toBe('approved');
});

test('partner status changes require the manage-people permission', function () {
    $person = Person::create(['organization' => 'Acme Relief', 'is_organization' => true]);
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->postJson("/json/people/{$person->id}/partner-status", [
        'to_status' => 'approved',
    ])->assertStatus(403);
});
