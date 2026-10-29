<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Helpers\Gtin;
use App\Models\CartonCode;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\OfflineModeSetting;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

// 036000291452 is a valid UPC-A (the standard worked example).
const UPC_EACH = '036000291452';

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('upc-lookup-budget');
    config(['upc.enabled' => true]);
});

function upcItem(string $name = 'Tissues', ?string $upc = null, ?int $caseQty = null, float $size = 1): Item
{
    static $n = 0;
    $n++;
    $unitId = DB::table('units')->where('abbreviation', 'each')->value('id')
        ?? DB::table('units')->insertGetId(['abbreviation' => 'each', 'name' => 'each']);
    $itemtype = ItemType::create([
        'name' => $name, 'family' => sprintf('%04d', 800 + $n), 'variant' => '00', 'status' => 'orderable',
        'unit_id' => $unitId, 'category_id' => Category::firstOrCreate(['name' => 'Hygiene'])->id,
    ]);
    $packagetypeId = DB::table('packagetypes')->first()->id
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Each', 'plural' => 'Each']);

    return Item::create(['itemtype_id' => $itemtype->id, 'packagetypes_id' => $packagetypeId, 'description' => $name, 'upc' => $upc, 'case_qty' => $caseQty, 'size' => $size]);
}

function offProduct(string $name = 'Kleenex Tissues'): array
{
    return ['status' => 1, 'product' => ['product_name' => $name, 'brands' => 'Kleenex, Other', 'quantity' => '3 boxes', 'categories' => 'Paper goods']];
}

test('gtin helpers normalize, validate and guess carton codes', function () {
    expect(Gtin::normalize('036000291452'))->toBe('00036000291452')
        ->and(Gtin::normalize('0036000291452'))->toBe('00036000291452')
        ->and(Gtin::normalize('12345'))->toBeNull()
        ->and(Gtin::isValid('00036000291452'))->toBeTrue()
        ->and(Gtin::isValid('00036000291453'))->toBeFalse()
        ->and(Gtin::kind('00036000291452'))->toBe('each')
        ->and(Gtin::kind('10036000291459'))->toBe('carton');

    // a case code is the unit code under indicator 1, with its own check digit
    $carton = '1'.'0'.substr('036000291452', 0, 11);
    $carton .= Gtin::checkDigit($carton);
    expect(Gtin::isValid($carton))->toBeTrue()
        ->and(Gtin::guessEachCode($carton))->toBe('00036000291452');
});

test('a hand-keyed items.upc matches whichever spelling is scanned', function () {
    $item = upcItem('Tissues', UPC_EACH);
    $sorter = userWithPermissions('manage-sorting');
    Http::fake();

    foreach ([UPC_EACH, '0'.UPC_EACH, '00'.UPC_EACH] as $scan) {
        $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.$scan)
            ->assertOk()->assertJsonPath('status', 'matched')->assertJsonPath('item.id', $item->id)->assertJsonPath('qty', null);
    }
    Http::assertNothingSent();
});

test('an unknown barcode asks Open Food Facts and suggests, without storing anything', function () {
    Http::fake(['world.openfoodfacts.org/*' => Http::response(offProduct())]);
    $sorter = userWithPermissions('manage-sorting');

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertOk()
        ->assertJsonPath('status', 'unknown')->assertJsonPath('kind', 'each')->assertJsonPath('online', 'found')
        ->assertJsonPath('suggestion.description', 'Kleenex Tissues')->assertJsonPath('suggestion.brand', 'Kleenex')
        ->assertJsonPath('suggestion.size_text', '3 boxes')->assertJsonPath('suggestion.source', 'openfoodfacts');

    expect(Item::count())->toBe(0); // a suggestion is never stored
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/product/0036000291452.json')
        && str_contains($request->header('User-Agent')[0], 'ReliefInventory'));
});

test('lookup falls through to Open Products Facts when Open Food Facts does not know it', function () {
    Http::fake([
        'world.openfoodfacts.org/*' => Http::response(['status' => 0, 'status_verbose' => 'product not found'], 404),
        'world.openproductsfacts.org/*' => Http::response(offProduct('Dish Soap')),
    ]);

    $this->actingAs(userWithPermissions('manage-sorting'))->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertJsonPath('online', 'found')->assertJsonPath('suggestion.source', 'openproductsfacts')
        ->assertJsonPath('suggestion.description', 'Dish Soap');
});

test('lookup also falls through when the first service is down', function () {
    Http::fake([
        'world.openfoodfacts.org/*' => Http::response('oops', 503),
        'world.openproductsfacts.org/*' => Http::response(offProduct('Dish Soap')),
    ]);

    $this->actingAs(userWithPermissions('manage-sorting'))->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertJsonPath('online', 'found')->assertJsonPath('suggestion.source', 'openproductsfacts');
});

test('a barcode nobody knows is reported none, cached, and not looked up again', function () {
    Http::fake(['*' => Http::response(['status' => 0], 404)]);
    $sorter = userWithPermissions('manage-sorting');

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('online', 'none')->assertJsonPath('suggestion', null);
    Http::assertSentCount(2);

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)->assertJsonPath('online', 'none');
    Http::assertSentCount(2);
});

test('a failing service is reported unavailable and retried next time, never an error', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    $sorter = userWithPermissions('manage-sorting');

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('online', 'unavailable');
    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH);
    Http::assertSentCount(4);
});

test('offline mode and the config switch skip online lookups entirely', function () {
    Http::fake();
    $sorter = userWithPermissions('manage-sorting');

    OfflineModeSetting::current()->update(['enabled' => true]);
    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)->assertJsonPath('online', 'disabled');

    OfflineModeSetting::current()->update(['enabled' => false]);
    config(['upc.enabled' => false]);
    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)->assertJsonPath('online', 'disabled');
    Http::assertNothingSent();
});

test('the per-minute budget stops lookups before the services would ban us', function () {
    Http::fake(['*' => Http::response(['status' => 0], 404)]);
    config(['upc.max_requests_per_minute' => 2]);

    $this->actingAs(userWithPermissions('manage-sorting'))->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertJsonPath('online', 'none');
    $this->getJson('/json/upc/resolve?code=012345678905')->assertJsonPath('online', 'throttled');
    Http::assertSentCount(2);
});

test('a bad check digit or odd length is rejected as a misscan', function () {
    $sorter = userWithPermissions('manage-sorting');
    Http::fake();

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code=036000291453')->assertStatus(422)->assertJsonPath('status', 'invalid');
    $this->actingAs($sorter)->getJson('/json/upc/resolve?code=1234567')->assertStatus(422)->assertJsonPath('status', 'invalid');
    Http::assertNothingSent();
});

test('a case is just another item row: scanning it reports the single units inside', function () {
    $each = upcItem('Tissues', UPC_EACH);
    $body = '10'.substr(UPC_EACH, 0, 11);
    $carton = $body.Gtin::checkDigit($body);
    $case = upcItem('Tissues case', $carton, 24);
    $sorter = userWithPermissions('manage-sorting');
    Http::fake();

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.UPC_EACH)
        ->assertJsonPath('status', 'matched')->assertJsonPath('item.id', $each->id)->assertJsonPath('qty', null)->assertJsonPath('kind', 'each');
    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.$carton)
        ->assertJsonPath('status', 'matched')->assertJsonPath('item.id', $case->id)->assertJsonPath('kind', 'carton')->assertJsonPath('qty', 24);
    Http::assertNothingSent();
});

test('an unknown carton is guessed from the single-item row already in the master list', function () {
    $each = upcItem('Tissues', UPC_EACH);
    $body = '10'.substr(UPC_EACH, 0, 11);
    $carton = $body.Gtin::checkDigit($body);
    Http::fake();

    $this->actingAs(userWithPermissions('manage-sorting'))->getJson('/json/upc/resolve?code='.$carton)
        ->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('kind', 'carton')
        ->assertJsonPath('each_code', '00036000291452')->assertJsonPath('guess_item.id', $each->id)
        ->assertJsonPath('guess_item.itemtype_id', $each->itemtype_id)->assertJsonPath('online', 'skipped');
    Http::assertNothingSent();
});

test('an unknown carton also tries its guessed single-unit code online', function () {
    $body = '10'.substr(UPC_EACH, 0, 11);
    $carton = $body.Gtin::checkDigit($body);
    Http::fake(function ($request) {
        // only the single-unit code (0036000291452 as EAN-13) is known online
        return str_contains($request->url(), '0036000291452') && str_contains($request->url(), 'openfoodfacts')
            ? Http::response(offProduct())
            : Http::response(['status' => 0], 404);
    });

    $this->actingAs(userWithPermissions('manage-sorting'))->getJson('/json/upc/resolve?code='.$carton)
        ->assertJsonPath('kind', 'carton')->assertJsonPath('online', 'found')
        ->assertJsonPath('suggestion.code', '00036000291452');
});

test('barcode lookup needs sorting permission', function () {
    $this->actingAs(userWithPermissions('general-access'))->getJson('/json/upc/resolve?code='.UPC_EACH)->assertForbidden();
});

test('resolve with online=0 answers from the catalog without any network wait', function () {
    Http::fake();
    $sorter = userWithPermissions('manage-sorting');

    $this->actingAs($sorter)->getJson('/json/upc/resolve?online=0&code='.UPC_EACH)
        ->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('online', 'pending')->assertJsonPath('suggestion', null);
    Http::assertNothingSent();

    $item = upcItem('Tissues', UPC_EACH);
    $this->actingAs($sorter)->getJson('/json/upc/resolve?online=0&code='.UPC_EACH)
        ->assertJsonPath('status', 'matched')->assertJsonPath('item.id', $item->id);
});

test('suggest returns just the online half, and nothing for a code we already know', function () {
    Http::fake(['world.openfoodfacts.org/*' => Http::response(offProduct())]);
    $sorter = userWithPermissions('manage-sorting');

    $this->actingAs($sorter)->getJson('/json/upc/suggest?code='.UPC_EACH)
        ->assertOk()->assertJsonPath('online', 'found')->assertJsonPath('suggestion.description', 'Kleenex Tissues');

    upcItem('Tissues', UPC_EACH);
    Http::fake();
    $this->actingAs($sorter)->getJson('/json/upc/suggest?code='.UPC_EACH)
        ->assertOk()->assertJsonPath('online', 'skipped')->assertJsonPath('suggestion', null);
    $this->actingAs(userWithPermissions('general-access'))->getJson('/json/upc/suggest?code='.UPC_EACH)->assertForbidden();
});

// A real case: carton 10842515008167 holds 6 packs of 5 of the single item 842515006715.
// The carton's item number (00816) differs from the single's (00671), so the exact-guess
// fails; only the manufacturer prefix (842515) is shared.
const CARTON_REAL = '10842515008167';
const SINGLE_REAL = '842515006715';

test('an unknown carton whose item number differs offers same-manufacturer items as candidates', function () {
    $apricots = upcItem('Sunny Fruit Organic Apricots 1.76 oz', SINGLE_REAL);
    upcItem('Other brand', UPC_EACH);
    Http::fake();

    $this->actingAs(userWithPermissions('manage-sorting'))->getJson('/json/upc/resolve?online=0&code='.CARTON_REAL)
        ->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('kind', 'carton')
        ->assertJsonPath('guess_item', null)
        ->assertJsonCount(1, 'candidates')->assertJsonPath('candidates.0.id', $apricots->id);
});

test('confirming a carton maps it to the single item with packs x items, and later scans select that item with the quantity', function () {
    $apricots = upcItem('Sunny Fruit Organic Apricots 1.76 oz', SINGLE_REAL);
    $sorter = userWithPermissions('manage-sorting');
    Http::fake();

    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => CARTON_REAL, 'item_id' => $apricots->id])
        ->assertStatus(422)->assertJsonValidationErrors('qty_per_carton');
    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => '10842515008168', 'item_id' => $apricots->id, 'qty_per_carton' => 30])
        ->assertStatus(422)->assertJsonValidationErrors('code'); // bad check digit

    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => CARTON_REAL, 'item_id' => $apricots->id, 'inner_packs' => 6, 'items_per_pack' => 5])
        ->assertCreated()->assertJsonPath('status', 'matched')->assertJsonPath('qty', 30)->assertJsonPath('kind', 'carton');

    $this->actingAs($sorter)->getJson('/json/upc/resolve?code='.CARTON_REAL)
        ->assertJsonPath('status', 'matched')->assertJsonPath('item.id', $apricots->id)->assertJsonPath('qty', 30)
        ->assertJsonPath('inner_packs', 6)->assertJsonPath('items_per_pack', 5);
    Http::assertNothingSent();

    // a typed total also works, and re-confirming updates instead of duplicating
    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => CARTON_REAL, 'item_id' => $apricots->id, 'qty_per_carton' => 36])->assertCreated();
    expect(CartonCode::count())->toBe(1)->and(CartonCode::first()->qty_per_carton)->toBe(36);
});

test('carton codes: sorting permission to confirm, item permission to review or remove', function () {
    $item = upcItem('Soup', SINGLE_REAL);
    $this->actingAs(userWithPermissions('general-access'))
        ->postJson('/json/upc/cartons', ['code' => CARTON_REAL, 'item_id' => $item->id, 'qty_per_carton' => 30])->assertForbidden();

    $sorter = userWithPermissions('manage-sorting');
    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => CARTON_REAL, 'item_id' => $item->id, 'qty_per_carton' => 30])->assertCreated();
    expect(CartonCode::first()->person_id_user)->toBe($sorter->id);

    $this->actingAs($sorter)->getJson('/json/carton-codes')->assertForbidden();
    $admin = userWithPermissions('manage-items');
    $this->actingAs($admin)->getJson('/json/carton-codes')->assertOk()->assertJsonCount(1, 'records');
    $this->actingAs($admin)->deleteJson('/json/carton-codes/'.CartonCode::first()->id)->assertOk();
    expect(CartonCode::count())->toBe(0);
});

test('a carton whose item number matches the single is recognised outright (10845561000871 holds 12 of 845561000874)', function () {
    $item = upcItem('Single item', '845561000874');
    Http::fake();
    $sorter = userWithPermissions('manage-sorting');

    $this->actingAs($sorter)->getJson('/json/upc/resolve?online=0&code=10845561000871')
        ->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('kind', 'carton')
        ->assertJsonPath('guess_item.id', $item->id)->assertJsonPath('candidates', []);

    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => '10845561000871', 'item_id' => $item->id, 'qty_per_carton' => 12])
        ->assertCreated()->assertJsonPath('qty', 12);
    Http::assertNothingSent();
});

test('the Carton Barcodes screen is for item managers and the list says who added each carton', function () {
    $item = upcItem('Soup', SINGLE_REAL);
    $sorter = userWithPermissions('manage-sorting');
    $this->actingAs($sorter)->postJson('/json/upc/cartons', ['code' => CARTON_REAL, 'item_id' => $item->id, 'inner_packs' => 6, 'items_per_pack' => 5])->assertCreated();

    $this->actingAs($sorter)->get('/setup/carton-codes')->assertForbidden();
    $admin = userWithPermissions('manage-items');
    $this->actingAs($admin)->getJson('/json/carton-codes')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('records.0.qty_per_carton', 30)
        ->assertJsonPath('records.0.inner_packs', 6)->assertJsonPath('records.0.entered_by_name', Person::find($sorter->id)->full_name)->assertJsonMissingPath('records.0.entered_by');
});
