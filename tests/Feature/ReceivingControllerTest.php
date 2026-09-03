<?php

use App\Models\DonationOffer;
use App\Models\Driver;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('recording a donation intake creates it in received status', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'container_count' => 8,
        'manifest' => 'Assorted canned goods, roughly 8 pallets.',
        'manifest_weight_lbs' => 12000,
    ])->assertCreated()->json('record');

    $donation = Transaction::findOrFail($record['id']);

    expect($donation->status->name)->toBe(Transaction::STATUS_RECEIVED)
        ->and($donation->category)->toBe('donation')
        ->and((float) $donation->manifest_weight_lbs)->toBe(12000.0);
});

test('driver, arrival method, container type, and source address are captured on intake', function () {
    $user = userWithPermissions('manage-receiving');
    $driver = Driver::create(['name' => 'Pat Driver', 'phone' => '555-0100']);

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'driver_id' => $driver->id,
        'arrival_method' => 'semi',
        'container_types' => ['box', 'tote'],
        'container_type_counts' => ['box' => 4, 'tote' => 2],
        'container_count' => 6,
        'source_address' => '123 Main St',
        'source_city' => 'Asheville',
        'source_state' => 'NC',
        'source_zip' => '28801',
    ])->assertCreated()->json('record');

    $donation = Transaction::findOrFail($record['id']);

    expect($donation->driver_id)->toBe($driver->id)
        ->and($donation->arrival_method)->toBe('semi')
        ->and($donation->container_types)->toBe(['box', 'tote'])
        ->and($donation->container_type_counts)->toBe(['box' => 4, 'tote' => 2])
        ->and($donation->container_count)->toBe(6)
        ->and($donation->source_address)->toBe('123 Main St')
        ->and($donation->source_city)->toBe('Asheville')
        ->and($donation->source_state)->toBe('NC')
        ->and($donation->source_zip)->toBe('28801');
});

test('pallets cannot be combined with other container types', function () {
    $user = userWithPermissions('manage-receiving');

    $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'container_types' => ['pallet', 'box'],
    ])->assertStatus(422);
});

test('a trailer pulled by a pickup truck is a valid arrival method', function () {
    $user = userWithPermissions('manage-receiving');

    $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'arrival_method' => 'trailer',
    ])->assertCreated();
});

test('an intake defaults to today but accepts a backdated order date', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'order_date' => '2026-08-01',
    ])->assertCreated()->json('record');

    expect(Transaction::findOrFail($record['id'])->order_date)->toBe('2026-08-01');
});

test('a contact person for the shipment can be linked, distinct from the donor', function () {
    $user = userWithPermissions('manage-receiving');
    $org = Person::create(['organization' => 'Big Org', 'is_organization' => true]);
    $contact = Person::create(['first_name' => 'Cam', 'last_name' => 'Contact', 'parent_person_id' => $org->id]);

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'person_id' => $org->id,
        'contact_person_id' => $contact->id,
    ])->assertCreated()->json('record');

    $donation = Transaction::findOrFail($record['id']);
    expect($donation->person_id)->toBe($org->id)
        ->and($donation->contact_person_id)->toBe($contact->id);
});

test('pallets can be created as loose boxes or bags, not just pallets/gaylords', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/pallets', ['count' => 2, 'container_type' => 'box'])
        ->assertCreated()->json('records');

    expect($records)->toHaveCount(2)
        ->and(Pallet::where('orderdonation_id', $donation->id)->where('container_type', 'box')->count())->toBe(2);
});

test('a non-donation category is logged but never enters the donation pipeline', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'equipment',
        'manifest' => 'A pallet jack, donated.',
    ])->assertCreated()->json('record');

    $donation = Transaction::findOrFail($record['id']);

    expect($donation->status->name)->toBe(Transaction::STATUS_LOGGED);
});

test('creating pallets for a donation links them and puts them in received status', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/pallets', ['count' => 3])
        ->assertCreated()->json('records');

    expect($records)->toHaveCount(3)
        ->and(Pallet::where('orderdonation_id', $donation->id)->count())->toBe(3)
        ->and(Pallet::where('orderdonation_id', $donation->id)->where('status', 'received')->count())->toBe(3);
});

test('close-out fires only when exactly one pallet remains, already in sorting', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $p1 = Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);
    $p1->statuses()->create(['status' => 'received']);
    $p2 = Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);
    $p2->statuses()->create(['status' => 'received']);

    // Not a candidate yet: two pallets still open.
    $this->actingAs($user)->postJson('/json/receiving/'.$donation->id.'/close-out')->assertStatus(422);

    $p1->transitionTo('sorting');
    $p1->transitionTo('empty');
    $p2->transitionTo('sorting'); // exactly one non-empty, already in sorting

    $this->actingAs($user)->postJson('/json/receiving/'.$donation->id.'/close-out')->assertOk();

    expect($p2->fresh()->status)->toBe('empty')
        ->and($donation->fresh()->status->name)->toBe(Transaction::STATUS_COMPLETE);
});

test('the Receiving dashboard flags close-out candidates on each record', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $p1 = Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);
    $p1->statuses()->create(['status' => 'received']);
    $p1->transitionTo('sorting');

    $response = $this->actingAs($user)->getJson('/json/receiving')->assertOk();
    $records = $response->json('records');

    expect($records)->toHaveCount(1)
        ->and($records[0]['is_close_out_candidate'])->toBeTrue();
});

test('updating an intake edits its fields', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
        'manifest' => 'Original manifest text.',
    ]);

    $this->actingAs($user)->putJson('/json/receiving/'.$donation->id, [
        'category' => 'donation',
        'container_count' => 12,
        'manifest' => 'Corrected: actually 12 pallets.',
    ])->assertOk();

    expect($donation->fresh()->manifest)->toBe('Corrected: actually 12 pallets.')
        ->and($donation->fresh()->container_count)->toBe(12);
});

test('category cannot change once pallets exist for the intake', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);

    $this->actingAs($user)->putJson('/json/receiving/'.$donation->id, [
        'category' => 'equipment',
    ])->assertStatus(422);

    expect($donation->fresh()->category)->toBe('donation');
});

test('an intake with no pallets can be deleted', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->deleteJson('/json/receiving/'.$donation->id)->assertOk();

    expect(Transaction::find($donation->id))->toBeNull();
});

test('an intake with pallets already created cannot be deleted', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    Pallet::create(['kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donation->id, 'datepacked' => now()->toDateString()]);

    $this->actingAs($user)->deleteJson('/json/receiving/'.$donation->id)->assertStatus(422);

    expect(Transaction::find($donation->id))->not->toBeNull();
});

test('pallet lines never carry content description or item at receiving — that happens at sorting', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    // packagetypes has no timestamp columns, so insert below Eloquent
    $packageTypeId = DB::table('packagetypes')
        ->insertGetId(['plural' => 'Cases', 'singular' => 'Case']);
    $item = Item::create([
        'packagetypes_id' => $packageTypeId,
        'pluscode' => '0001',
        'description' => 'Ketchup, 24ct case',
        'active' => true,
    ]);

    $records = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/pallets', [
            'count' => 4,
            // Even if a client sends these (old UI, direct API call), the
            // endpoint no longer accepts or stores them.
            'content_description' => 'Mixed pallet',
            'content_item_id' => $item->id,
        ])
        ->assertCreated()->json('records');

    expect($records)->toHaveCount(4)
        ->and(collect($records)->pluck('content_description')->unique()->all())->toBe([null])
        ->and(collect($records)->pluck('content_item_id')->unique()->all())->toBe([null]);
});

test('quick_sort_candidate is a donation-level flag, not per-pallet', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'quick_sort_candidate' => true,
    ])->assertCreated()->json('record');

    expect(Transaction::findOrFail($record['id'])->quick_sort_candidate)->toBeTrue();
});

test('recategorizing an intake re-derives its lifecycle status', function () {
    $user = userWithPermissions('manage-receiving');

    // other -> donation must enter the sorting pipeline as Received
    $logged = Transaction::create([
        'type' => 'donation', 'category' => 'other',
        'status_id' => Transaction::statusId(Transaction::STATUS_LOGGED),
        'order_date' => now()->toDateString(),
    ]);
    $this->actingAs($user)
        ->putJson('/json/receiving/'.$logged->id, ['category' => 'donation'])
        ->assertOk();
    expect($logged->fresh()->status->name)->toBe(Transaction::STATUS_RECEIVED);

    // donation -> supplies must leave the pipeline as Logged
    $received = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $this->actingAs($user)
        ->putJson('/json/receiving/'.$received->id, ['category' => 'supplies'])
        ->assertOk();
    expect($received->fresh()->status->name)->toBe(Transaction::STATUS_LOGGED);
});

test('logged non-donation intakes stay visible in the receiving list', function () {
    $user = userWithPermissions('manage-receiving');
    Transaction::create([
        'type' => 'donation', 'category' => 'equipment',
        'status_id' => Transaction::statusId(Transaction::STATUS_LOGGED),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)->getJson('/json/receiving')->assertOk()->json('records');

    expect(collect($records)->pluck('category'))->toContain('equipment');
});

test('a donation can be flagged donor_identification_pending at intake and defaults to false', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'donor_identification_pending' => true,
    ])->assertCreated()->json('record');
    expect(Transaction::findOrFail($record['id'])->donor_identification_pending)->toBeTrue();

    $unflagged = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
    ])->assertCreated()->json('record');
    expect(Transaction::findOrFail($unflagged['id'])->donor_identification_pending)->toBeFalse();
});

test('a flagged donation stays visible in the receiving list even after it reaches Complete', function () {
    $user = userWithPermissions('manage-receiving');
    $flagged = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
        'order_date' => now()->toDateString(),
        'donor_identification_pending' => true,
    ]);
    $unflaggedComplete = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
        'order_date' => now()->toDateString(),
    ]);

    $records = $this->actingAs($user)->getJson('/json/receiving')->assertOk()->json('records');
    $ids = collect($records)->pluck('id');

    expect($ids)->toContain($flagged->id)
        ->not->toContain($unflaggedComplete->id);
});

test('the donor identification flag can be cleared once a donor is identified', function () {
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
        'donor_identification_pending' => true,
    ]);

    $this->actingAs($user)->putJson('/json/receiving/'.$donation->id, [
        'category' => 'donation',
        'donor_identification_pending' => false,
    ])->assertOk();

    expect($donation->fresh()->donor_identification_pending)->toBeFalse();
});

test('a photo of the shipment can be uploaded and served back, guarded by existence', function () {
    Storage::fake('local');
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->post('/json/receiving/'.$donation->id.'/photo', ['photo' => UploadedFile::fake()->image('load.jpg')])
        ->assertOk();

    $donation->refresh();
    expect($donation->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($donation->photo_path);

    $this->actingAs($user)->get('/json/receiving/'.$donation->id.'/photo')->assertOk();
});

test('an intake matched to a pending donation offer links and transitions it', function () {
    $user = userWithPermissions('manage-receiving');
    $donor = Person::create(['first_name' => 'Offer', 'last_name' => 'Donor']);
    $offer = DonationOffer::create([
        'person_id' => $donor->id,
        'status' => DonationOffer::STATUS_PENDING,
    ]);

    $record = $this->actingAs($user)->postJson('/json/receiving', [
        'category' => 'donation',
        'person_id' => $donor->id,
        'donation_offer_id' => $offer->id,
    ])->assertCreated()->json('record');

    expect($offer->fresh()->status)->toBe(DonationOffer::STATUS_RECEIVED)
        ->and($offer->fresh()->donation_id)->toBe($record['id']);
});

test('replacing a shipment photo deletes the old file', function () {
    Storage::fake('local');
    $user = userWithPermissions('manage-receiving');
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $this->actingAs($user)->post('/json/receiving/'.$donation->id.'/photo', ['photo' => UploadedFile::fake()->image('first.jpg')]);
    $firstPath = $donation->fresh()->photo_path;

    $this->actingAs($user)->post('/json/receiving/'.$donation->id.'/photo', ['photo' => UploadedFile::fake()->image('second.jpg')]);

    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($donation->fresh()->photo_path);
});

test('pre-printed labels can be created unassigned, then attached to a donation by tag', function () {
    $user = userWithPermissions('manage-receiving');

    $preprinted = $this->actingAs($user)->postJson('/json/receiving/preprint-labels', [
        'count' => 2,
    ])->assertCreated()->json('records');

    expect($preprinted)->toHaveCount(2);
    foreach ($preprinted as $pallet) {
        expect($pallet['orderdonation_id'])->toBeNull()
            ->and($pallet['donor_person_id'])->toBeNull();
    }

    $donor = Person::create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $donation = Transaction::create([
        'type' => 'donation', 'category' => 'donation', 'person_id' => $donor->id,
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $tags = collect($preprinted)->map(fn ($p) => Pallet::find($p['id'])->tag)->all();

    $response = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donation->id.'/attach-pallets', ['tags' => $tags])
        ->assertOk();

    expect($response->json('records'))->toHaveCount(2)
        ->and($response->json('failed'))->toBeEmpty();

    foreach ($preprinted as $pallet) {
        $fresh = Pallet::find($pallet['id']);
        expect($fresh->orderdonation_id)->toBe($donation->id)
            ->and($fresh->donor_person_id)->toBe($donor->id);
    }
});

test('attaching an unknown or already-assigned label tag is reported as failed, not a fatal error', function () {
    $user = userWithPermissions('manage-receiving');

    $donationA = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);
    $donationB = Transaction::create([
        'type' => 'donation', 'category' => 'donation',
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED),
        'order_date' => now()->toDateString(),
    ]);

    $alreadyAssigned = Pallet::create([
        'kind' => 'R', 'status' => 'received', 'orderdonation_id' => $donationA->id,
        'datepacked' => now()->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/json/receiving/'.$donationB->id.'/attach-pallets', [
            'tags' => [$alreadyAssigned->tag, 'R99999999', 'not-a-tag'],
        ])
        ->assertOk();

    expect($response->json('records'))->toBeEmpty()
        ->and($response->json('failed'))->toHaveCount(3);

    expect($alreadyAssigned->fresh()->orderdonation_id)->toBe($donationA->id);
});
