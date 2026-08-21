<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\PackageType;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Maps Flowtrac's "Donations Received" log (Date,Reference,Account,
 * Product,Description,Qty,UOM,Status) onto donation Transactions + opening
 * ItemLedger entries. Each row is an already-completed historical intake
 * event, not a live in-progress workflow — imported straight to
 * STATUS_COMPLETE rather than replaying Received -> Sorting (there's no
 * pallet data to replay it against).
 *
 * Reference is empty on every real row seen (confirmed against the full
 * 1,388-row export) and Account is blank on ~97.5% of rows — both match
 * the incomplete-intake-information design (donor_identification_pending
 * + the "Unknown Donor" system Person). With no natural row id, the
 * dedup/source_ref key is Date+Product+an occurrence index for repeated
 * Date+Product pairs within the same file — stable across re-imports of
 * an unchanged export, since rows are read in the same order each time,
 * but not a guarantee against a re-ordered or edited export.
 */
class FlowtracDonationsReceivedImporter implements Importer
{
    use FlowtracOrgLookup;

    /**
     * Set by ImporterRegistry from Auth::id() before process() runs — the
     * staff member who ran the import, recorded as person_id_user on each
     * created Transaction (never trusted from client input, per the usual
     * convention — this is set server-side by the controller/registry).
     */
    public ?int $actorId = null;

    private const PACKAGE_TYPE_MAP = [
        'e' => 'Each',
        'ea' => 'Each',
        'c' => 'Case',
        'p' => 'Pallet',
        'bg' => 'Bag',
    ];

    // See FlowtracProductsImporter's doc comment — itemtypes.category_id
    // is NOT NULL with no default, so an ItemType created here (Products.csv
    // wasn't imported yet, or this SKU wasn't in it) needs a real category.
    // Shares the exact same catch-all name as FlowtracProductsImporter so
    // both importers land unmapped items in one place, not two.
    private const FALLBACK_CATEGORY_NAME = 'Uncategorized (Import)';

    public function source(): string
    {
        return 'flowtrac';
    }

    public function fileType(): string
    {
        return 'flowtrac_donations_received';
    }

    public function process(string $absolutePath, bool $commit): ImportRunResult
    {
        $result = new ImportRunResult;
        $this->orgCache = [];

        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $occurrenceCounts = [];
        $rowNumber = 0;

        foreach ($csv->getRecords() as $row) {
            $rowNumber++;

            $status = trim((string) ($row['Status'] ?? ''));
            if ($status !== '' && strcasecmp($status, 'Valid') !== 0) {
                $result->addRow($rowNumber, null, 'skipped', "Status is \"{$status}\", not Valid.", $row);

                continue;
            }

            $date = trim((string) ($row['Date'] ?? ''));
            $product = trim((string) ($row['Product'] ?? ''));
            $qty = (float) ($row['Qty'] ?? 0);
            $account = trim((string) ($row['Account'] ?? ''));

            if ($date === '' || $product === '' || $qty <= 0) {
                $result->addRow($rowNumber, null, 'error', 'Missing Date, Product, or a positive Qty.', $row);

                continue;
            }

            $dedupKey = strtolower($date.'|'.$product);
            $occurrenceCounts[$dedupKey] = ($occurrenceCounts[$dedupKey] ?? 0) + 1;
            $sourceKey = 'flowtrac:donation:'.$dedupKey.'#'.$occurrenceCounts[$dedupKey];

            if (! preg_match('/^(.+)-([A-Za-z]+)$/', $product, $m)) {
                $result->addRow($rowNumber, $sourceKey, 'skipped', 'No UOM suffix on this product code — cannot match a catalog item.', $row);

                continue;
            }
            [, $baseCode, $suffix] = $m;
            $packageTypeName = self::PACKAGE_TYPE_MAP[strtolower($suffix)] ?? null;
            if (! $packageTypeName) {
                $result->addRow($rowNumber, $sourceKey, 'skipped', "Unrecognized UOM suffix \"{$suffix}\" — needs a manual decision.", $row);

                continue;
            }

            if (! $commit) {
                $existing = Transaction::where('source_system', 'flowtrac')->where('source_ref', $sourceKey)->first();
                $result->addRow($rowNumber, $sourceKey, $existing ? 'skipped' : 'created', $existing ? 'Already imported.' : null, $row, 'Transaction', $existing?->id);

                continue;
            }

            $existing = Transaction::where('source_system', 'flowtrac')->where('source_ref', $sourceKey)->first();
            if ($existing) {
                $result->addRow($rowNumber, $sourceKey, 'skipped', 'Already imported.', $row, 'Transaction', $existing->id);

                continue;
            }

            $itemTypeSourceKey = 'flowtrac:product:'.strtolower($baseCode);
            $itemType = ItemType::where('source_system', 'flowtrac')->where('source_ref', $itemTypeSourceKey)->first();
            $packageType = PackageType::where('singular', $packageTypeName)->first();

            try {
                if (! $itemType) {
                    // Products.csv wasn't imported yet (or this SKU wasn't
                    // in it) — create a minimal catalog entry now so the
                    // donation itself isn't blocked, same sort_hold /
                    // pending-review pattern as FlowtracProductsImporter.
                    $itemType = ItemType::create([
                        'name' => trim((string) ($row['Description'] ?? '')) ?: $baseCode,
                        'description' => trim((string) ($row['Description'] ?? '')) ?: null,
                        'status' => 'sort_hold',
                        'category_id' => Category::firstOrCreate(['name' => self::FALLBACK_CATEGORY_NAME])->id,
                        'unit_id' => self::fallbackUnitId(),
                        'active' => true,
                        'source_system' => 'flowtrac',
                        'source_ref' => $itemTypeSourceKey,
                    ]);
                }

                $item = Item::where('itemtype_id', $itemType->id)->where('packagetypes_id', $packageType->id)->first();
                if (! $item) {
                    $item = Item::create([
                        'itemtype_id' => $itemType->id,
                        'packagetypes_id' => $packageType->id,
                        'pluscode' => '0000',
                        'active' => true,
                    ]);
                }

                $donorPending = $account === '';
                $person = $donorPending
                    ? Person::where('system_key', 'unknown-donor')->first()
                    : $this->findOrBuildOrgPerson($account, true);

                $donation = Transaction::create([
                    'type' => 'donation',
                    'category' => 'donation',
                    'person_id' => $person?->id,
                    'person_id_user' => $this->actorId,
                    'donor_identification_pending' => $donorPending,
                    'order_date' => substr($date, 0, 10),
                    'status_id' => Transaction::statusId(Transaction::STATUS_COMPLETE),
                    'comments' => 'Imported from Flowtrac Donations Received.',
                    'source_system' => 'flowtrac',
                    'source_ref' => $sourceKey,
                ]);

                // Note: item_ledgers has no description/created_by/
                // transaction_type/reference_id columns despite what
                // ItemLedger::$fillable lists (pre-existing schema/model
                // mismatch, out of scope here) — only the real columns.
                ItemLedger::create([
                    'orderdonation_id' => $donation->id,
                    'item_id' => $item->id,
                    'qty_added' => $qty,
                    'qty_subtracted' => 0,
                    'disposition' => 'usable',
                ]);

                $result->addRow($rowNumber, $sourceKey, 'created', null, $row, 'Transaction', $donation->id);
            } catch (\Throwable $e) {
                $result->addRow($rowNumber, $sourceKey, 'error', $e->getMessage(), $row);
            }
        }

        return $result;
    }

    /**
     * See FlowtracProductsImporter's identical helper — reuses UnitsSeeder's
     * pre-existing "unknown" unit via a raw insert, since `units` has no
     * timestamp columns and Unit doesn't declare $timestamps = false.
     */
    private static function fallbackUnitId(): int
    {
        return Unit::where('name', 'unknown')->value('id')
            ?? DB::table('units')->insertGetId(['name' => 'unknown', 'abbreviation' => 'unknown', 'type' => 'other']);
    }
}
