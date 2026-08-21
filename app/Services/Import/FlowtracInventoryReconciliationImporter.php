<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\ItemType;
use App\Models\PackageType;
use League\Csv\Reader;

/**
 * Flowtrac's Current Inventory export (Product,Description,OnHand,
 * "Category 1") is used purely as a reconciliation check against
 * reliefinventory's own ledger-derived stock-on-hand — never a write path.
 * Posting OnHand as ledger entries lives entirely in
 * FlowtracDonationsReceivedImporter (each donation row is its own
 * historical event); doing it here too would double-count the same stock
 * through two different import paths. Both preview() and commit() behave
 * identically (this importer never writes anything) — every "row" here is
 * reported 'skipped' (matches) or 'error' (mismatch, needing a look) so
 * the batch history/error list surfaces discrepancies without pretending
 * anything was imported.
 */
class FlowtracInventoryReconciliationImporter implements Importer
{
    private const PACKAGE_TYPE_MAP = [
        'e' => 'Each',
        'ea' => 'Each',
        'c' => 'Case',
        'p' => 'Pallet',
        'bg' => 'Bag',
    ];

    public function source(): string
    {
        return 'flowtrac';
    }

    public function fileType(): string
    {
        return 'flowtrac_current_inventory';
    }

    public function process(string $absolutePath, bool $commit): ImportRunResult
    {
        $result = new ImportRunResult;

        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $rowNumber = 0;
        foreach ($csv->getRecords() as $row) {
            $rowNumber++;

            $product = trim((string) ($row['Product'] ?? ''));
            $flowtracOnHand = (float) ($row['OnHand'] ?? 0);

            if (! preg_match('/^(.+)-([A-Za-z]+)$/', $product, $m)) {
                $result->addRow($rowNumber, null, 'skipped', 'No UOM suffix — cannot match a catalog item to reconcile.', $row);

                continue;
            }
            [, $baseCode, $suffix] = $m;
            $packageTypeName = self::PACKAGE_TYPE_MAP[strtolower($suffix)] ?? null;

            $itemType = ItemType::where('source_system', 'flowtrac')->where('source_ref', 'flowtrac:product:'.strtolower($baseCode))->first();
            $packageType = $packageTypeName ? PackageType::where('singular', $packageTypeName)->first() : null;
            $item = ($itemType && $packageType) ? Item::where('itemtype_id', $itemType->id)->where('packagetypes_id', $packageType->id)->first() : null;

            if (! $item) {
                $result->addRow($rowNumber, "flowtrac:product:{$product}", 'error', 'No matching reliefinventory item found — import Products.csv first, or this SKU was never imported.', $row);

                continue;
            }

            $reliefOnHand = (float) ItemLedger::where('item_id', $item->id)
                ->where('disposition', 'usable')
                ->selectRaw('COALESCE(SUM(qty_added), 0) - COALESCE(SUM(qty_subtracted), 0) as balance')
                ->value('balance');

            if (abs($reliefOnHand - $flowtracOnHand) < 0.01) {
                $result->addRow($rowNumber, "flowtrac:product:{$product}", 'skipped', null, $row, 'Item', $item->id);
            } else {
                $result->addRow($rowNumber, "flowtrac:product:{$product}", 'error', "Flowtrac OnHand={$flowtracOnHand}, reliefinventory ledger balance={$reliefOnHand}.", $row, 'Item', $item->id);
            }
        }

        return $result;
    }
}
