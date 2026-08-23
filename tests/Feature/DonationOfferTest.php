<?php

use App\Models\DonationOffer;
use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

function makeOffer(array $overrides = []): DonationOffer
{
    $donor = Person::create(['first_name' => 'Test', 'last_name' => 'Donor']);

    return DonationOffer::create(array_merge([
        'person_id' => $donor->id,
        'status' => DonationOffer::STATUS_OFFERED,
        'description' => 'A truckload of canned goods.',
    ], $overrides));
}

test('creating an offer writes an initial status log row', function () {
    $user = userWithPermissions('manage-receiving');
    $donor = Person::create(['first_name' => 'Test', 'last_name' => 'Donor']);

    $record = $this->actingAs($user)->postJson('/json/donation-offers', [
        'person_id' => $donor->id,
        'description' => 'Canned goods, roughly 4 pallets.',
        'contact_method' => 'phone',
    ])->assertCreated()->json('record');

    $offer = DonationOffer::findOrFail($record['id']);

    expect($offer->status)->toBe(DonationOffer::STATUS_OFFERED)
        ->and($offer->statusLogs)->toHaveCount(1)
        ->and($offer->statusLogs->first()->from_status)->toBeNull()
        ->and($offer->statusLogs->first()->to_status)->toBe(DonationOffer::STATUS_OFFERED)
        ->and($offer->statusLogs->first()->contact_method)->toBe('phone');
});

test('status_date always reflects the current status, not the original creation date', function () {
    $recorder = userWithPermissions('manage-receiving');
    $decider = userWithPermissions('manage-donation-offers');
    $donor = Person::create(['first_name' => 'Test', 'last_name' => 'Donor']);

    Carbon::setTestNow('2026-08-01');
    $offerId = $this->actingAs($recorder)->postJson('/json/donation-offers', [
        'person_id' => $donor->id,
    ])->assertCreated()->json('record.id');
    $offer = DonationOffer::findOrFail($offerId);
    expect($offer->status_date)->toBe('2026-08-01');

    Carbon::setTestNow('2026-08-05');
    $this->actingAs($decider)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => '2026-08-10',
    ])->assertOk();
    expect($offer->fresh()->status_date)->toBe('2026-08-05');

    Carbon::setTestNow('2026-08-12');
    $this->actingAs($decider)->postJson("/json/donation-offers/{$offer->id}/cancel", [
        'cancelled_reason' => 'No longer needed.',
    ])->assertOk();
    expect($offer->fresh()->status_date)->toBe('2026-08-12');

    Carbon::setTestNow();
});

test('approving an offered donation requires an eta and moves it to pending', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offer = makeOffer();

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/approve", [])
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDays(2)->toDateString(),
        'transit_notes' => 'Leaving Friday morning.',
        'contact_method' => 'phone',
    ])->assertOk();

    $offer->refresh();
    expect($offer->status)->toBe(DonationOffer::STATUS_PENDING)
        ->and($offer->transit_notes)->toBe('Leaving Friday morning.')
        // makeOffer() creates the offer directly (not via the store()
        // endpoint), so there's no initial "offered" log row here — just
        // the one this approve() call appends.
        ->and($offer->statusLogs)->toHaveCount(1)
        ->and($offer->statusLogs->last()->from_status)->toBe(DonationOffer::STATUS_OFFERED)
        ->and($offer->statusLogs->last()->to_status)->toBe(DonationOffer::STATUS_PENDING);
});

test('approving accepts an eta date range and rejects an end before the start', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offer = makeOffer();

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDays(3)->toDateString(),
        'eta_end' => now()->addDays(1)->toDateString(),
    ])->assertStatus(422);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDays(1)->toDateString(),
        'eta_end' => now()->addDays(3)->toDateString(),
    ])->assertOk();

    $offer->refresh();
    expect($offer->eta_start)->toBe(now()->addDays(1)->toDateString())
        ->and($offer->eta_end)->toBe(now()->addDays(3)->toDateString())
        ->and($offer->etaRangeLabel())->toBe(
            now()->addDays(1)->format('M j').' – '.now()->addDays(3)->format('M j')
        );
});

test('approve is rejected once the offer has already moved past offered', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offer = makeOffer(['status' => DonationOffer::STATUS_PENDING]);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDay()->toDateString(),
    ])->assertStatus(422);
});

test('refusing an offer requires a reason and is terminal', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offer = makeOffer();

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/refuse", [])
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/refuse", [
        'refused_reason' => 'Item not needed right now.',
    ])->assertOk();

    $offer->refresh();
    expect($offer->status)->toBe(DonationOffer::STATUS_REFUSED)
        ->and($offer->refused_reason)->toBe('Item not needed right now.');
});

test('diverting an offer requires a destination', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offer = makeOffer();

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/divert", [])
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/divert", [
        'diverted_to' => 'Another partner warehouse in Statesville.',
    ])->assertOk();

    expect($offer->fresh()->status)->toBe(DonationOffer::STATUS_DIVERTED)
        ->and($offer->fresh()->diverted_to)->toBe('Another partner warehouse in Statesville.');
});

test('cancelling requires a reason and only applies to a pending offer', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offered = makeOffer();

    $this->actingAs($user)->postJson("/json/donation-offers/{$offered->id}/cancel", [
        'cancelled_reason' => 'Too soon.',
    ])->assertStatus(422);

    $pending = makeOffer(['status' => DonationOffer::STATUS_PENDING]);

    $this->actingAs($user)->postJson("/json/donation-offers/{$pending->id}/cancel", [])
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/json/donation-offers/{$pending->id}/cancel", [
        'cancelled_reason' => 'Donor backed out.',
    ])->assertOk();

    expect($pending->fresh()->status)->toBe(DonationOffer::STATUS_CANCELLED);
});

test('matching a pending offer to an already-arrived donation links them', function () {
    $user = userWithPermissions('manage-donation-offers');
    $offer = makeOffer(['status' => DonationOffer::STATUS_PENDING]);
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/match", [
        'donation_id' => $donation->id,
    ])->assertOk();

    $offer->refresh();
    expect($offer->status)->toBe(DonationOffer::STATUS_RECEIVED)
        ->and($offer->donation_id)->toBe($donation->id);
});

test('matching a donation already linked to another offer is rejected', function () {
    $user = userWithPermissions('manage-donation-offers');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $firstOffer = makeOffer(['status' => DonationOffer::STATUS_PENDING]);
    $firstOffer->transitionTo(DonationOffer::STATUS_RECEIVED, null, ['donation_id' => $donation->id]);

    $secondOffer = makeOffer(['status' => DonationOffer::STATUS_PENDING]);

    $this->actingAs($user)->postJson("/json/donation-offers/{$secondOffer->id}/match", [
        'donation_id' => $donation->id,
    ])->assertStatus(422);
});

test('matching at intake via ReceivingController transitions the offer in the same request', function () {
    $user = userWithPermissions('manage-receiving');
    $offer = makeOffer(['status' => DonationOffer::STATUS_PENDING]);

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'person_id' => $offer->person_id,
        'donation_offer_id' => $offer->id,
    ])->assertCreated()->json('record');

    $offer->refresh();
    expect($offer->status)->toBe(DonationOffer::STATUS_RECEIVED)
        ->and($offer->donation_id)->toBe($record['id']);
});

test('matching at intake is rejected if the referenced offer is not pending', function () {
    $user = userWithPermissions('manage-receiving');
    $offer = makeOffer(); // still 'offered', not pending

    $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'donation_offer_id' => $offer->id,
    ])->assertStatus(422);

    expect(Transaction::where('type', 'donation')->count())->toBe(0);
});

test('editing is blocked once an offer has reached a terminal status', function () {
    $user = userWithPermissions('manage-receiving');
    $offer = makeOffer(['status' => DonationOffer::STATUS_REFUSED, 'refused_reason' => 'No longer needed.']);

    $this->actingAs($user)->putJson("/json/donation-offers/{$offer->id}", [
        'person_id' => $offer->person_id,
        'description' => 'Changed my mind about the description.',
    ])->assertStatus(422);
});

test('a manage-receiving-only user can log offers but not decide them', function () {
    $recorder = userWithPermissions('manage-receiving');
    $donor = Person::create(['first_name' => 'Test', 'last_name' => 'Donor']);

    $this->actingAs($recorder)->postJson('/json/donation-offers', [
        'person_id' => $donor->id,
    ])->assertCreated();

    $offer = makeOffer();

    $this->actingAs($recorder)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDay()->toDateString(),
    ])->assertForbidden();
});

test('a manage-donation-offers user can perform every decision action', function () {
    $decider = userWithPermissions('manage-donation-offers', 'manage-receiving');
    $offer = makeOffer();

    $this->actingAs($decider)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDay()->toDateString(),
    ])->assertOk();
});

test('a full offered to pending to received lifecycle produces one ordered log row per transition', function () {
    $user = userWithPermissions('manage-donation-offers', 'manage-receiving');
    $donor = Person::create(['first_name' => 'Full', 'last_name' => 'Lifecycle']);
    $offerId = $this->actingAs($user)->postJson('/json/donation-offers', [
        'person_id' => $donor->id,
    ])->assertCreated()->json('record.id');
    $offer = DonationOffer::findOrFail($offerId);
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/approve", [
        'eta_start' => now()->addDay()->toDateString(),
    ])->assertOk();
    $this->actingAs($user)->postJson("/json/donation-offers/{$offer->id}/match", [
        'donation_id' => $donation->id,
    ])->assertOk();

    $logs = $offer->fresh()->statusLogs;
    expect($logs)->toHaveCount(3)
        ->and($logs[0]->to_status)->toBe(DonationOffer::STATUS_OFFERED)
        ->and($logs[1]->from_status)->toBe(DonationOffer::STATUS_OFFERED)
        ->and($logs[1]->to_status)->toBe(DonationOffer::STATUS_PENDING)
        ->and($logs[2]->from_status)->toBe(DonationOffer::STATUS_PENDING)
        ->and($logs[2]->to_status)->toBe(DonationOffer::STATUS_RECEIVED)
        ->and($logs->pluck('changed_by_person_id')->unique()->all())->toBe([$user->id]);
});
