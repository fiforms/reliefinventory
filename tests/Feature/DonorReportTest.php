<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Category;
use App\Models\DonorStandingLog;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Person;
use App\Models\Status;
use App\Models\Transaction;
use App\Services\DonorStanding;
use Illuminate\Support\Facades\DB;

function donorItem(): Item
{
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $type = ItemType::firstOrCreate(['name' => 'Canned Soup'], [
        'family' => '0900', 'variant' => '00', 'status' => 'orderable', 'unit_id' => $unitId,
        'category_id' => Category::firstOrCreate(['name' => 'Food'])->id,
    ]);
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Case')->value('id')
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Case', 'plural' => 'Cases']);

    return Item::firstOrCreate(['itemtype_id' => $type->id], ['packagetypes_id' => $packagetypeId, 'description' => 'Soup']);
}

/** A sorted donation from $donor: [usable, outdated, trashed, diverted] quantities. */
function donorDonation(Person $donor, array $qty, string $date): Transaction
{
    $donation = Transaction::create([
        'type' => 'donation', 'person_id' => $donor->id, 'order_date' => $date,
        'status_id' => Status::firstOrCreate(['name' => 'Complete'])->id,
    ]);
    $item = donorItem();
    foreach (array_combine(['usable', 'outdated', 'trashed', 'diverted'], $qty) as $disposition => $n) {
        if ($n > 0) {
            $donation->itemLedgers()->create(['item_id' => $item->id, 'qty_added' => $n, 'disposition' => $disposition]);
        }
    }

    return $donation;
}

test('the report and its exports need view-reports; setting a standing needs manage-donor-standing', function () {
    $user = userWithPermissions('general-access');
    $donor = Person::create(['organization' => 'Acme']);

    $this->actingAs($user)->getJson('/json/reports/donors')->assertForbidden();
    $this->actingAs($user)->get('/report/donors.csv')->assertForbidden();
    $this->actingAs($user)->get('/report/donors.pdf')->assertForbidden();
    $this->actingAs($user)->get('/reports/donors')->assertForbidden();

    $viewer = userWithPermissions('view-reports');
    $this->actingAs($viewer)->putJson("/json/people/{$donor->id}/donor-standing", ['standing' => 'watch'])->assertForbidden();
});

test('donors roll up by disposition with loss percent, trend and a suggestion', function () {
    $user = userWithPermissions('view-reports');
    $bad = Person::create(['organization' => 'Bad Co']);
    $good = Person::create(['organization' => 'Good Co']);
    $new = Person::create(['organization' => 'New Co']);

    // Bad Co: 3 donations, 150 units, 60 lost (20 outdated + 40 trashed) → 40%.
    donorDonation($bad, [20, 10, 20, 0], '2026-08-01');
    donorDonation($bad, [20, 10, 20, 0], '2026-09-01');
    donorDonation($bad, [50, 0, 0, 0], '2026-10-01');
    // Good Co: tiny loss over plenty of donations and units.
    foreach (['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $date) {
        donorDonation($good, [200, 0, 4, 20], $date);
    }
    // New Co: one tiny donation, and one still unsorted.
    donorDonation($new, [10, 0, 5, 0], '2026-10-02');
    Transaction::create(['type' => 'donation', 'person_id' => $new->id, 'order_date' => '2026-10-03',
        'status_id' => Status::firstOrCreate(['name' => 'Received'])->id]);

    $report = $this->actingAs($user)->getJson('/json/reports/donors')->assertOk()->json();
    $byName = collect($report['donors'])->keyBy('name');

    expect($byName['Bad Co'])->toMatchArray([
        'donations' => 3, 'units' => 150, 'usable' => 90, 'outdated' => 20, 'trashed' => 40, 'diverted' => 0,
        'loss_percent' => 40.0, 'suggested' => 'watch', 'standing' => 'normal',
    ])->and($byName['Bad Co']['donation_list'])->toHaveCount(3)
        ->and($byName['Bad Co']['donation_list'][0]['date'])->toBe('2026-10-01')
        // Last three donations are all of them here: still 40%.
        ->and($byName['Bad Co']['recent_loss_percent'])->toEqual(40.0);

    // 5 × [200 usable, 4 trashed, 20 diverted] = 1120 units, 20 lost → 1.8%.
    expect($byName['Good Co'])->toMatchArray(['donations' => 5, 'suggested' => 'preferred'])
        ->and($byName['Good Co']['loss_percent'])->toEqual(1.8);

    expect($byName['New Co'])->toMatchArray(['donations' => 1, 'unsorted_donations' => 1, 'suggested' => null]);

    // Biggest givers first.
    expect($report['donors'][0]['name'])->toBe('Good Co')
        ->and($report['totals']['donors'])->toBe(3)
        ->and($report['totals']['needs_review'])->toBe(2); // Bad Co → watch, Good Co → preferred
});

test('the suggestion thresholds', function () {
    expect(DonorStanding::suggest(1, 1000, 90.0)['standing'])->toBeNull()           // one donation isn't a pattern
        ->and(DonorStanding::suggest(5, 10, 90.0)['standing'])->toBeNull()          // too few units
        ->and(DonorStanding::suggest(2, 100, 35.0)['standing'])->toBe('watch')
        ->and(DonorStanding::suggest(2, 100, 80.0)['standing'])->toBe('watch')      // decline needs 3+ donations
        ->and(DonorStanding::suggest(3, 100, 55.0)['standing'])->toBe('decline')
        ->and(DonorStanding::suggest(5, 600, 3.0)['standing'])->toBe('preferred')
        ->and(DonorStanding::suggest(5, 600, 10.0)['standing'])->toBe('normal')
        ->and(DonorStanding::lossPercent(0, 0, 0))->toBeNull();
});

test('diverted goods are not counted against a donor', function () {
    $user = userWithPermissions('view-reports');
    $donor = Person::create(['organization' => 'Divert Co']);
    donorDonation($donor, [50, 0, 0, 50], '2026-10-01');

    $report = $this->actingAs($user)->getJson('/json/reports/donors')->json();
    $row = collect($report['donors'])->firstWhere('name', 'Divert Co');

    expect($row['loss_percent'])->toEqual(0.0)->and($row['units'])->toBe(100);
});

test('the date range filters donations', function () {
    $user = userWithPermissions('view-reports');
    $donor = Person::create(['organization' => 'Acme']);
    donorDonation($donor, [10, 0, 0, 0], '2026-01-01');
    donorDonation($donor, [10, 0, 0, 0], '2026-10-01');

    $report = $this->actingAs($user)->getJson('/json/reports/donors?from=2026-09-01')->json();

    expect($report['donors'][0]['donations'])->toBe(1);
});

test('setting a standing is logged, validated, and the system donor is exempt', function () {
    $user = userWithPermissions('manage-donor-standing');
    $donor = Person::create(['organization' => 'Acme']);

    $this->actingAs($user)->putJson("/json/people/{$donor->id}/donor-standing", ['standing' => 'bogus'])->assertStatus(422);
    // A decline has to say why.
    $this->actingAs($user)->putJson("/json/people/{$donor->id}/donor-standing", ['standing' => 'decline'])->assertStatus(422);

    $this->actingAs($user)->putJson("/json/people/{$donor->id}/donor-standing", ['standing' => 'watch', 'note' => 'Two bad loads'])
        ->assertOk();
    $this->actingAs($user)->putJson("/json/people/{$donor->id}/donor-standing", ['standing' => 'decline', 'note' => 'Mostly trash'])
        ->assertOk();

    $donor->refresh();
    expect($donor->donor_standing)->toBe('decline')
        ->and($donor->donor_standing_note)->toBe('Mostly trash')
        ->and((int) $donor->donor_standing_set_by)->toBe($user->id);

    $log = DonorStandingLog::where('person_id', $donor->id)->orderBy('id')->get();
    expect($log->pluck('to_standing')->all())->toBe(['watch', 'decline'])
        ->and($log[1]->from_standing)->toBe('watch')
        ->and((int) $log[1]->person_id_user)->toBe($user->id);

    $unknown = Person::where('system_key', 'unknown-donor')->first();
    if ($unknown) {
        $this->actingAs($user)->putJson("/json/people/{$unknown->id}/donor-standing", ['standing' => 'watch'])->assertStatus(422);
    }
});

test('a client cannot set the standing through the people endpoint', function () {
    $user = userWithPermissions('manage-people');
    $donor = Person::create(['organization' => 'Acme']);

    $this->actingAs($user)->putJson("/json/people/{$donor->id}", ['organization' => 'Acme', 'donor_standing' => 'decline']);

    expect($donor->fresh()->donor_standing)->toBe('normal');
});

test('csv and pdf export the same report', function () {
    $user = userWithPermissions('view-reports');
    donorDonation(Person::create(['organization' => 'Acme']), [10, 1, 1, 0], '2026-10-01');

    expect($this->actingAs($user)->get('/report/donors.csv')->assertOk()->getContent())->toContain('Acme');
    $this->actingAs($user)->get('/report/donors.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
});
