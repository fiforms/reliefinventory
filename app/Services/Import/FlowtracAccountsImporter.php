<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Person;
use App\Models\PersonCategory;
use League\Csv\Reader;

/**
 * Maps Flowtrac's Accounts.csv (Account,Type,Default Contact,Office,Cell,
 * Email,Active) onto the org Person's category tag. Optional and
 * order-independent relative to FlowtracContactsImporter — the Flowtrac
 * review found Accounts.csv's 10 sample rows are NOT the real account
 * list (739 real accounts appear only in Contacts.csv), so this importer
 * only ever enriches an org Person that FlowtracOrgLookup can find-or-
 * create; it never becomes the sole source of truth for which accounts
 * exist.
 */
class FlowtracAccountsImporter implements Importer
{
    use FlowtracOrgLookup;

    public function source(): string
    {
        return 'flowtrac';
    }

    public function fileType(): string
    {
        return 'flowtrac_accounts';
    }

    public function process(string $absolutePath, bool $commit): ImportRunResult
    {
        $result = new ImportRunResult;
        $this->orgCache = [];

        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $rowNumber = 0;
        foreach ($csv->getRecords() as $row) {
            $rowNumber++;

            $accountName = trim((string) ($row['Account'] ?? ''));
            $type = trim((string) ($row['Type'] ?? ''));

            if ($accountName === '') {
                $result->addRow($rowNumber, null, 'error', 'Missing Account.', $row);

                continue;
            }

            $sourceKey = $this->sourceRefForAccount($accountName);

            if (! $commit) {
                $existingCategory = $type !== '' ? PersonCategory::whereRaw('LOWER(name) = ?', [strtolower($type)])->first() : null;
                if ($type !== '' && ! $existingCategory) {
                    $result->flagDecision("Account type \"{$type}\" has no matching category yet — it will be created on Commit.");
                }
                $org = Person::where('source_system', 'flowtrac')->where('source_ref', $sourceKey)->first()
                    ?? Person::where('is_organization', true)->whereRaw('LOWER(organization) = ?', [strtolower($accountName)])->first();
                $result->addRow($rowNumber, $sourceKey, $org ? 'updated' : 'created', null, $row, 'Person', $org?->id);

                continue;
            }

            $org = $this->findOrBuildOrgPerson($accountName, true);

            try {
                if ($type !== '') {
                    $category = PersonCategory::firstOrCreate(
                        ['name' => $type],
                    );
                    $org->update(['category_id' => $category->id]);
                }

                $result->addRow($rowNumber, $sourceKey, 'updated', null, $row, 'Person', $org->id);
            } catch (\Throwable $e) {
                $result->addRow($rowNumber, $sourceKey, 'error', $e->getMessage(), $row);
            }
        }

        return $result;
    }
}
