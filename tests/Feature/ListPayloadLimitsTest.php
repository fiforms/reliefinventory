<?php

use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Pallet;
use App\Models\PalletStatus;
use App\Models\Person;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

// Lists that only ever grow (history, closed-out pallets, old offers) are bounded server-side: a year of
// real activity made /json/palletstatus 10 MB. These pin the bounds so they don't quietly come back.

function makePallet(string $kind, string $status): Pallet
{
    return Pallet::create(['kind' => $kind, 'status' => $status, 'container_type' => 'pallet', 'datepacked' => now()->toDateString()]);
}

test('pallet movement history returns the newest rows, capped, or one pallet in full', function () {
    $this->actingAs(userWithPermissions('manage-pallets', 'general-access'));
    $a = makePallet('W', 'sealed');
    $b = makePallet('W', 'sealed');
    foreach (range(1, 8) as $i) {
        PalletStatus::create(['pallet_id' => $a->id, 'status' => 'sealed', 'notes' => "a{$i}"]);
    }
    PalletStatus::create(['pallet_id' => $b->id, 'status' => 'sealed', 'notes' => 'b1']);

    $this->getJson('/json/palletstatus?limit=5')->assertOk()->assertJsonCount(5, 'records')
        ->assertJsonPath('records.0.notes', 'b1'); // newest first

    $this->getJson('/json/palletstatus?pallet_id='.$a->id)->assertOk()->assertJsonCount(8, 'records');
    $this->getJson('/json/palletstatus')->assertOk()->assertJsonCount(9, 'records');
});

test('the pallet list shows live pallets by default and closed-out ones on request', function () {
    $this->actingAs(userWithPermissions('manage-pallets'));
    $live = [makePallet('R', 'received'), makePallet('R', 'sorting'), makePallet('W', 'open'), makePallet('S', 'ready'), makePallet('Q', 'held')];
    $closed = [makePallet('R', 'empty'), makePallet('W', 'empty'), makePallet('S', 'shipped'), makePallet('Q', 'condemned')];
    $missing = makePallet('W', 'sealed');
    $missing->markMissing();

    $ids = fn ($url) => collect($this->getJson($url)->assertOk()->json('records'))->pluck('id')->sort()->values()->all();

    $default = $ids('/json/pallets');
    expect($default)->toEqualCanonicalizing([...collect($live)->pluck('id')->all(), $missing->id]);
    expect($ids('/json/pallets?all=1'))->toHaveCount(10);
    // an explicit kind/status still reaches the closed-out ones
    expect($ids('/json/pallets/R/empty'))->toBe([$closed[0]->id]);
    expect($ids('/json/pallets?all=1&limit=3'))->toHaveCount(3);
});

test('the donation offers list keeps every open offer but only recent closed ones', function () {
    $this->actingAs(userWithPermissions('manage-receiving'));
    $donor = Person::create(['first_name' => 'Dee', 'last_name' => 'Donor']);
    $row = fn ($status) => ['person_id' => $donor->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now()];

    DB::table('donation_offers')->insert([$row('offered'), $row('pending')]);
    DB::table('donation_offers')->insert(array_map(fn () => $row('refused'), range(1, 160)));

    $records = collect($this->getJson('/json/donation-offers')->assertOk()->json('records'));
    expect($records)->toHaveCount(152)
        ->and($records->whereIn('status', ['offered', 'pending']))->toHaveCount(2);

    // the server-side status filter (Receiving's picker) is unaffected
    $this->getJson('/json/donation-offers?status=pending')->assertOk()->assertJsonCount(1, 'records');
});

test('the donor history on an offer carries only the columns the page shows', function () {
    $this->actingAs(userWithPermissions('manage-receiving'));
    $donor = Person::create(['first_name' => 'Dee', 'last_name' => 'Donor']);
    Transaction::create(['type' => 'donation', 'person_id' => $donor->id, 'status_id' => Transaction::statusId('Received'), 'order_date' => now()->toDateString(), 'manifest' => 'a long manifest']);
    DB::table('donation_offers')->insert(['person_id' => $donor->id, 'status' => 'offered', 'created_at' => now(), 'updated_at' => now()]);

    $history = $this->getJson('/json/donation-offers')->assertOk()->json('records.0.person.order_donations.0');

    expect($history)->toHaveKeys(['id', 'type', 'order_date', 'status'])->not->toHaveKey('manifest');
});

test('the legacy donations list is capped', function () {
    $this->actingAs(userWithPermissions('manage-orders'));
    foreach (range(1, 6) as $i) {
        Transaction::create(['type' => 'donation', 'status_id' => Transaction::statusId('Received'), 'order_date' => now()->toDateString()]);
    }

    $this->getJson('/json/donations?limit=4')->assertOk()->assertJsonCount(4, 'records');
});

test('the menu loads in a fixed number of queries however many items there are', function () {
    $user = userWithPermissions('general-access');
    $this->actingAs($user);
    $page = DB::table('pages')->value('id') ?? Page::create(['menu_title' => 'Main', 'hashtag' => 'main'])->id;

    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/json/menu-data')->assertOk();

        return count(DB::getQueryLog());
    };
    $add = fn ($n) => collect(range(1, $n))->each(fn ($i) => MenuItem::create(['page_id' => $page, 'link_text' => "Item {$i}-".uniqid(), 'link_url' => '/x'.uniqid(), 'order' => $i, 'permission_key' => $i % 2 ? 'general-access' : 'admin-system']));

    $add(3);
    $few = $count();
    $add(40);
    expect($count())->toBe($few);

    // and still filters by permission
    $items = collect($this->getJson('/json/menu-data')->json())->flatMap(fn ($p) => $p['menu_items'])->pluck('permission_key');
    expect($items)->not->toContain('admin-system');
});

test('a page with no menu entry still gets a breadcrumb instead of crashing', function () {
    expect(MenuItem::getBreadcrumb('/pallet-management'))->toBe([['href' => '/pallet-management', 'title' => 'Pallet Management']]);
});
