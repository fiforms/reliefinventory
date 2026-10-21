<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Helpers\Gtin;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\OfflineModeSetting;
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
