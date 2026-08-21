<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\PackageType;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Maps Flowtrac's Products.csv (Product,Description,OnHand,"Pick OnOrder",
 * "Category 1") onto reliefinventory's ItemType/Item catalog. Deliberately
 * catalog-only — it does NOT post any ItemLedger entries for OnHand. Two
 * reasons: (1) OnHand is a point-in-time balance, and Products.csv and the
 * separate Current Inventory export represent the same concept — posting
 * an opening-balance entry here as well as reconciling Current Inventory
 * separately would double-count; (2) a repeated commit (this app runs
 * alongside Flowtrac for a while, not a one-time cutover) would re-add the
 * same OnHand as a fresh ledger entry every time, which isn't idempotent.
 * Stock-on-hand reconciliation belongs entirely to the Current Inventory
 * import, which compares against the ledger's running balance instead of
 * blindly adding to it.
 *
 * itemtypes.category_id and unit_id are both NOT NULL with no default
 * (pre-existing schema — see the original itemtypes migration), so an item
 * whose Flowtrac category doesn't map onto reliefinventory's 15 categories
 * still needs real values to create the row at all: FALLBACK_CATEGORY_NAME
 * is a dedicated catch-all (matching the sort_hold "quick-added, pending
 * review" pattern already used for the rest of the row's uncertainty), and
 * unit_id reuses UnitsSeeder's own pre-existing "unknown" unit rather than
 * inventing a new one.
 */
class FlowtracProductsImporter implements Importer
{
    private const FALLBACK_CATEGORY_NAME = 'Uncategorized (Import)';

    /**
     * Flowtrac's `<base code>-<UOM suffix>` scheme, confirmed against the
     * real Products.csv (1,553 rows): E/C/P/Bx/Bg dominate, but the
     * encoding visibly degrades at scale — EA/Ea (alt-case Each), a bare
     * "B", "loan", "ACS", and 11 rows with no suffix at all. Only the
     * well-attested forms are auto-mapped; anything else is skipped with
     * an explanation rather than guessed.
     */
    private const PACKAGE_TYPE_MAP = [
        'e' => 'Each',
        'ea' => 'Each',
        'c' => 'Case',
        'p' => 'Pallet',
        'bg' => 'Bag',
        // Bx/Box does NOT exist as a PackageType yet (reliefinventory's own
        // seeder folded a "Box" item into "Case" once already) — left
        // unmapped deliberately so it surfaces as a decision, not a guess.
    ];

    /**
     * Flowtrac's 23 real `Category 1` values (confirmed against the real
     * Products.csv) translated onto reliefinventory's 15 numeric-block
     * categories — a best-guess mapping, not a verified 1:1, so Preview
     * must show it explicitly (see flagDecision calls below) rather than
     * silently committing it.
     */
    private const CATEGORY_MAP = [
        'animal products' => 'Animal products & durable medical',
        'dme' => 'Animal products & durable medical',
        'baby/child products' => 'Baby & child',
        'building supplies' => 'Building supplies',
        'cleaning supplies' => 'Cleaning & household',
        'household misc.' => 'Cleaning & household',
        'clothing outerwear' => 'Clothing & accessories',
        'disposables' => 'Paper & disposables',
        'drink' => 'Food & water',
        'food nonperish.' => 'Food & water',
        'food perishable' => 'Food & water',
        'furniture' => 'Furniture',
        'kitchen products' => 'Kitchen & dining',
        'linens bathroom' => 'Bedding & linens',
        'linens bedroom' => 'Bedding & linens',
        'linens misc.' => 'Bedding & linens',
        'outdoor products' => 'Outdoor, emergency & tools',
        'personal care prod' => 'Personal care, pharmacy & safety',
        'personal safety' => 'Personal care, pharmacy & safety',
        'pharm' => 'Personal care, pharmacy & safety',
        // Deliberately unmapped: "ACS Stock" and "Misc." are too vague to
        // place safely; "Appliances" is ambiguous between reliefinventory's
        // separate large/small-appliance categories and needs a per-item
        // call, not a blanket default.
    ];

    public function source(): string
    {
        return 'flowtrac';
    }

    public function fileType(): string
    {
        return 'flowtrac_products';
    }

    public function process(string $absolutePath, bool $commit): ImportRunResult
    {
        $result = new ImportRunResult;

        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $unmappedSuffixes = [];
        $unmappedCategories = [];

        $rowNumber = 0;
        foreach ($csv->getRecords() as $row) {
            $rowNumber++;

            $product = trim((string) ($row['Product'] ?? ''));
            $description = trim((string) ($row['Description'] ?? ''));

            if ($product === '') {
                $result->addRow($rowNumber, null, 'error', 'Missing Product code.', $row);

                continue;
            }

            if (! preg_match('/^(.+)-([A-Za-z]+)$/', $product, $m)) {
                $unmappedSuffixes['(no suffix)'] = ($unmappedSuffixes['(no suffix)'] ?? 0) + 1;
                $result->addRow($rowNumber, "flowtrac:product:{$product}", 'skipped', 'No UOM suffix on this product code — needs a manual decision.', $row);

                continue;
            }

            [, $baseCode, $suffix] = $m;
            $packageTypeName = self::PACKAGE_TYPE_MAP[strtolower($suffix)] ?? null;

            if (! $packageTypeName) {
                $unmappedSuffixes[$suffix] = ($unmappedSuffixes[$suffix] ?? 0) + 1;
                $result->addRow($rowNumber, "flowtrac:product:{$product}", 'skipped', "Unrecognized UOM suffix \"{$suffix}\" — needs a manual decision (e.g. \"Bx\"/Box has no matching PackageType yet).", $row);

                continue;
            }

            $categoryRaw = strtolower(trim((string) ($row['Category 1'] ?? '')));
            $categoryName = self::CATEGORY_MAP[$categoryRaw] ?? null;
            if ($categoryRaw !== '' && ! $categoryName) {
                $unmappedCategories[$row['Category 1']] = true;
            }

            $itemTypeSourceKey = 'flowtrac:product:'.strtolower($baseCode);

            if (! $commit) {
                $existing = ItemType::where('source_system', 'flowtrac')->where('source_ref', $itemTypeSourceKey)->first();
                $result->addRow($rowNumber, $itemTypeSourceKey, $existing ? 'updated' : 'created', null, $row, 'ItemType', $existing?->id);

                continue;
            }

            try {
                $itemType = ItemType::where('source_system', 'flowtrac')->where('source_ref', $itemTypeSourceKey)->first();
                $wasNew = ! $itemType;

                if (! $itemType) {
                    $itemType = ItemType::create([
                        'name' => $description ?: $baseCode,
                        'description' => $description ?: null,
                        // No family/variant assigned yet — pending
                        // supervisor review/numbering, same as any
                        // quick-added item type at sorting intake.
                        'status' => 'sort_hold',
                        'category_id' => $categoryName
                            ? Category::where('name', $categoryName)->value('id')
                            : Category::firstOrCreate(['name' => self::FALLBACK_CATEGORY_NAME])->id,
                        'unit_id' => self::fallbackUnitId(),
                        'active' => true,
                        'source_system' => 'flowtrac',
                        'source_ref' => $itemTypeSourceKey,
                    ]);
                }

                $packageType = PackageType::where('singular', $packageTypeName)->first();
                $item = Item::where('itemtype_id', $itemType->id)->where('packagetypes_id', $packageType->id)->first();
                if (! $item) {
                    Item::create([
                        'itemtype_id' => $itemType->id,
                        'packagetypes_id' => $packageType->id,
                        'pluscode' => '0000',
                        'active' => true,
                        'description' => $description ?: null,
                    ]);
                }

                $result->addRow($rowNumber, $itemTypeSourceKey, $wasNew ? 'created' : 'updated', null, $row, 'ItemType', $itemType->id);
            } catch (\Throwable $e) {
                $result->addRow($rowNumber, $itemTypeSourceKey, 'error', $e->getMessage(), $row);
            }
        }

        foreach ($unmappedSuffixes as $suffix => $count) {
            $result->flagDecision("{$count} product(s) use UOM suffix \"{$suffix}\", which has no PackageType mapping — skipped, needs a decision.");
        }
        foreach (array_keys($unmappedCategories) as $category) {
            $result->flagDecision("Flowtrac category \"{$category}\" has no reliefinventory category mapping — imported items are left uncategorized.");
        }

        return $result;
    }

    /**
     * Reuses UnitsSeeder's own pre-existing "unknown" unit rather than
     * inventing a new one. `units` has no timestamp columns (see the
     * original units migration) and Unit doesn't declare $timestamps =
     * false, so a raw insert is used here — the same workaround
     * UnitsSeeder/PackageTypesSeeder themselves already use for this exact
     * quirk, not something new to this importer.
     */
    private static function fallbackUnitId(): int
    {
        return Unit::where('name', 'unknown')->value('id')
            ?? DB::table('units')->insertGetId(['name' => 'unknown', 'abbreviation' => 'unknown', 'type' => 'other']);
    }
}
