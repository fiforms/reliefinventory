<?php

use App\Models\BuildingCloseout;
use App\Models\BuildingRollCall;
use App\Models\Permission;
use App\Models\Person;
use App\Models\VolunteerSignIn;
use Illuminate\Support\Facades\Hash;

function signInPerson(string $first, string $last, ?string $signedInAt = null): VolunteerSignIn
{
    $person = Person::create(['first_name' => $first, 'last_name' => $last, 'is_volunteer' => true]);

    return VolunteerSignIn::create([
        'person_id' => $person->id,
        'category' => VolunteerSignIn::CATEGORY_VOLUNTEER,
        'signed_in_at' => $signedInAt ?? now(),
        'status' => VolunteerSignIn::STATUS_OPEN,
    ]);
}

function kioskOperator(): Person
{
    $user = userWithPermissions('operate-volunteer-kiosk');
    $person = Person::find($user->id);
    $person->pin_hash = Hash::make('13579');
    $person->save();

    return $person;
}

test('occupying excludes a stale open sign-in once the building has been closed out', function () {
    $stale = signInPerson('Stale', 'Yesterday', now()->subDay());
    $today = signInPerson('Today', 'Now');

    expect(VolunteerSignIn::occupying()->pluck('id'))->toContain($stale->id, $today->id);

    BuildingCloseout::create(['closed_at' => now()->subHour()]);

    // Closeout happened before "today" signed in, so today still counts;
    // stale (signed in yesterday, before the closeout) does not.
    expect(VolunteerSignIn::occupying()->pluck('id'))
        ->toContain($today->id)
        ->not->toContain($stale->id);

    // The stale row itself is untouched — still open, no signed_out_at.
    expect($stale->fresh()->status)->toBe(VolunteerSignIn::STATUS_OPEN)
        ->and($stale->fresh()->signed_out_at)->toBeNull();
});

test('closeout requires a correct PIN from someone holding operate-volunteer-kiosk', function () {
    $operator = kioskOperator();

    $this->postJson('/json/building-safety/closeout', ['person_id' => $operator->id, 'pin' => '00000'])
        ->assertStatus(401);

    $this->postJson('/json/building-safety/closeout', ['person_id' => $operator->id, 'pin' => '13579'])
        ->assertOk();

    expect(BuildingCloseout::count())->toBe(1)
        ->and(BuildingCloseout::first()->closed_by_person_id)->toBe($operator->id);
});

test('closeout is rejected for a correct PIN without the permission', function () {
    $noPermission = Person::create(['first_name' => 'No', 'last_name' => 'Perm']);
    $noPermission->pin_hash = Hash::make('24680');
    $noPermission->save();

    $this->postJson('/json/building-safety/closeout', ['person_id' => $noPermission->id, 'pin' => '24680'])
        ->assertStatus(401);

    expect(BuildingCloseout::count())->toBe(0);
});

test('a roll call snapshots the occupying roster at start and only one can be active', function () {
    $operator = kioskOperator();
    $a = signInPerson('Alice', 'A');
    $b = signInPerson('Bob', 'B');

    $record = $this->postJson('/json/building-safety/roll-calls', ['person_id' => $operator->id, 'pin' => '13579'])
        ->assertCreated()->json('record');

    expect($record['total'])->toBe(2)
        ->and(collect($record['roster'])->pluck('id'))->toContain($a->id, $b->id);

    $this->postJson('/json/building-safety/roll-calls', ['person_id' => $operator->id, 'pin' => '13579'])
        ->assertStatus(422);
});

test('confirming people and closing a roll call works, and reports who is still missing', function () {
    $operator = kioskOperator();
    $viewer = userWithPermissions('view-building-occupancy');
    $a = signInPerson('Alice', 'A');
    $b = signInPerson('Bob', 'B');

    $rollCall = $this->postJson('/json/building-safety/roll-calls', ['person_id' => $operator->id, 'pin' => '13579'])
        ->json('record');

    $this->actingAs($viewer)
        ->postJson("/json/building-safety/roll-calls/{$rollCall['id']}/confirmations/{$a->id}")
        ->assertOk();

    $active = $this->actingAs($viewer)->getJson('/json/building-safety/roll-calls/active')->assertOk()->json('record');
    $missing = collect($active['roster'])->reject(fn ($r) => $r['confirmed']);
    expect($active['confirmed_count'])->toBe(1)
        ->and($missing->pluck('id')->all())->toBe([$b->id]);

    $this->postJson("/json/building-safety/roll-calls/{$rollCall['id']}/close", ['person_id' => $operator->id, 'pin' => '13579'])
        ->assertOk();

    expect(BuildingRollCall::find($rollCall['id'])->closed_at)->not->toBeNull();
});

test('a person with only operate-volunteer-kiosk, no other role, can still act as a closeout candidate', function () {
    $securityOfficer = Person::create(['first_name' => 'Sam', 'last_name' => 'Security']);
    $securityOfficer->pin_hash = Hash::make('11223');
    $securityOfficer->save();
    $permission = Permission::firstOrCreate(['key' => 'operate-volunteer-kiosk'], ['name' => 'operate-volunteer-kiosk']);
    $securityOfficer->person_permissions()->attach($permission->id, ['granted' => true]);

    $matches = $this->getJson('/json/building-safety/kiosk-operators?q=Security')->assertOk()->json('records');
    expect(collect($matches)->pluck('id'))->toContain($securityOfficer->id);

    $this->postJson('/json/building-safety/closeout', ['person_id' => $securityOfficer->id, 'pin' => '11223'])
        ->assertOk();
});
