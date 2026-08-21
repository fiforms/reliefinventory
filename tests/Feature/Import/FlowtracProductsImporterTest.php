<?php

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Services\Import\FlowtracProductsImporter;
use Illuminate\Support\Facades\DB;

// Fixture rows are drawn from real docs/flowtrac/datadumps/Products*.csv
// content, per the "small fixture CSVs built from real sample rows"
// verification requirement — not the full file.
function writeProductsFixture(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'flowtrac_products_').'.csv';
    $lines = ['Product,Description,OnHand,"Pick OnOrder","Category 1"'];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

beforeEach(function () {
    DB::table('packagetypes')->insert([
        ['singular' => 'Case', 'plural' => 'Cases'],
        ['singular' => 'Bag', 'plural' => 'Bags'],
    ]);
    // RefreshDatabase migrates but doesn't run CategoriesSeeder — the
    // mapped-to category needs to exist for the "recognized mapping"
    // tests, same as it would in any real, fully-seeded environment.
    Category::create(['name' => 'Animal products & durable medical']);
});

test('a recognized suffix and category creates a matching ItemType and Item', function () {
    $path = writeProductsFixture([
        ['020-Bg', 'Cat Food', '166', '0', 'Animal Products'],
    ]);

    (new FlowtracProductsImporter)->process($path, true);

    $itemType = ItemType::where('source_system', 'flowtrac')->where('source_ref', 'flowtrac:product:020')->first();

    expect($itemType)->not->toBeNull()
        ->and($itemType->status)->toBe('sort_hold')
        ->and($itemType->category->name)->toBe('Animal products & durable medical')
        ->and(Item::where('itemtype_id', $itemType->id)->exists())->toBeTrue();
});

test('an unrecognized UOM suffix (Box) is skipped, not guessed', function () {
    $path = writeProductsFixture([
        ['020-Bx', 'Cat Food', '2', '0', 'Animal Products'],
    ]);

    $result = (new FlowtracProductsImporter)->process($path, true);

    expect($result->skipped)->toBe(1)
        ->and(ItemType::where('source_system', 'flowtrac')->exists())->toBeFalse()
        ->and($result->rows[0]['error_message'])->toContain('Unrecognized UOM suffix');
});

test('an unmapped Flowtrac category still creates the item, uncategorized, and flags a decision', function () {
    $path = writeProductsFixture([
        ['999-C', 'Mystery Item', '5', '0', 'ACS Stock'],
    ]);

    $result = (new FlowtracProductsImporter)->process($path, true);

    $itemType = ItemType::where('source_system', 'flowtrac')->where('source_ref', 'flowtrac:product:999')->first();

    expect($itemType)->not->toBeNull()
        ->and($itemType->category->name)->toBe('Uncategorized (Import)')
        ->and($result->decisions)->toContain('Flowtrac category "ACS Stock" has no reliefinventory category mapping — imported items are left uncategorized.');
});

test('two UOM variants of the same base code share one ItemType with two Items', function () {
    $path = writeProductsFixture([
        ['020-Bg', 'Cat Food', '166', '0', 'Animal Products'],
        ['020-C', 'Cat Food', '10', '0', 'Animal Products'],
    ]);

    (new FlowtracProductsImporter)->process($path, true);

    $itemType = ItemType::where('source_system', 'flowtrac')->where('source_ref', 'flowtrac:product:020')->first();

    expect(ItemType::where('source_system', 'flowtrac')->count())->toBe(1)
        ->and(Item::where('itemtype_id', $itemType->id)->count())->toBe(2);
});
