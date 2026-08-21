<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Person;
use League\Csv\Reader;

/**
 * Maps Flowtrac's Contacts.csv (Account,Name,Office,Cell,Email,Active,
 * B-Default,S-Default) onto reliefinventory's org/contact Person model —
 * see person-tagging-and-org-contacts-design memory. This is the primary
 * account-discovery path (see FlowtracOrgLookup's doc comment): it creates
 * the org Person on demand from the Account column, so it doesn't depend
 * on Accounts.csv having been imported first or at all.
 */
class FlowtracContactsImporter implements Importer
{
    use FlowtracOrgLookup;

    public function source(): string
    {
        return 'flowtrac';
    }

    public function fileType(): string
    {
        return 'flowtrac_contacts';
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
            $contactName = trim((string) ($row['Name'] ?? ''));

            if ($accountName === '') {
                $result->addRow($rowNumber, null, 'error', 'Missing Account — cannot link this contact to an organization.', $row);

                continue;
            }

            $org = $this->findOrBuildOrgPerson($accountName, $commit);
            $orgSourceKey = $this->sourceRefForAccount($accountName);

            if ($contactName === '') {
                // Contacts.csv rows with no contact name still establish
                // the account itself — nothing further to do for this row
                // beyond the org lookup/creation above.
                $result->addRow($rowNumber, $orgSourceKey, $org ? 'updated' : 'created', null, $row, 'Person', $org?->id);

                continue;
            }

            $contactSourceKey = $orgSourceKey.':contact:'.strtolower($contactName);

            $roleParts = [];
            if (strcasecmp(trim((string) ($row['B-Default'] ?? '')), 'Yes') === 0) {
                $roleParts[] = 'Primary';
            }
            if (strcasecmp(trim((string) ($row['S-Default'] ?? '')), 'Yes') === 0) {
                // Not enforced exclusive — real Flowtrac data has multiple
                // contacts under one account simultaneously flagged
                // default; don't overwrite/clear a role a prior row set.
                $roleParts[] = 'Shipping Default';
            }
            $contactRole = $roleParts ? implode(', ', $roleParts) : null;

            $nameParts = preg_split('/\s+/', $contactName, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $existing = Person::where('source_system', 'flowtrac')->where('source_ref', $contactSourceKey)->first();

            if (! $commit) {
                $result->addRow($rowNumber, $contactSourceKey, $existing ? 'updated' : 'created', null, $row, 'Person', $existing?->id);

                continue;
            }

            $attributes = [
                'first_name' => $firstName,
                'last_name' => $lastName ?: null,
                'organization' => trim($accountName),
                'parent_person_id' => $org?->id,
                'contact_role' => $contactRole,
                'phone' => trim((string) ($row['Office'] ?? '')) ?: trim((string) ($row['Cell'] ?? '')) ?: null,
                'email' => trim((string) ($row['Email'] ?? '')) ?: null,
                'source_system' => 'flowtrac',
                'source_ref' => $contactSourceKey,
            ];

            try {
                if ($existing) {
                    $existing->update($attributes);
                    $result->addRow($rowNumber, $contactSourceKey, 'updated', null, $row, 'Person', $existing->id);
                } else {
                    $contact = Person::create($attributes);
                    $result->addRow($rowNumber, $contactSourceKey, 'created', null, $row, 'Person', $contact->id);
                }
            } catch (\Throwable $e) {
                // E.g. a duplicate email across two contacts — real data
                // isn't guaranteed clean; report and move on rather than
                // failing the whole batch.
                $result->addRow($rowNumber, $contactSourceKey, 'error', $e->getMessage(), $row);
            }
        }

        return $result;
    }
}
