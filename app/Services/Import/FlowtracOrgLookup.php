<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Person;

/**
 * Shared by FlowtracContactsImporter, FlowtracAccountsImporter, and
 * FlowtracDonationsReceivedImporter — all three need to resolve a Flowtrac
 * "Account" name to the same org Person record. The Flowtrac review found
 * 739 of the real accounts appear only in Contacts.csv, not Accounts.csv
 * (whose 10 rows are not the real account list) — so every importer that
 * encounters an account name must be able to create the org Person on
 * demand, not assume Accounts.csv has already been imported first. Import
 * order between the two files therefore doesn't matter.
 */
trait FlowtracOrgLookup
{
    /**
     * One cache per process() call — avoids re-querying/re-creating the
     * same org for every contact/donation row under one account within a
     * single file.
     *
     * @var array<string, Person>
     */
    private array $orgCache = [];

    private function sourceRefForAccount(string $accountName): string
    {
        return 'flowtrac:account:'.strtolower(trim($accountName));
    }

    /**
     * Find or (when $commit) create the org Person for $accountName.
     * Returns null on preview when no matching record exists yet — the
     * caller reports a proposed "would create" row instead of a real id.
     */
    private function findOrBuildOrgPerson(string $accountName, bool $commit): ?Person
    {
        $key = strtolower(trim($accountName));
        if ($key === '') {
            return null;
        }

        if (isset($this->orgCache[$key])) {
            return $this->orgCache[$key];
        }

        $sourceRef = $this->sourceRefForAccount($accountName);

        $org = Person::where('source_system', 'flowtrac')->where('source_ref', $sourceRef)->first();

        // Fall back to matching an existing, manually-entered org record by
        // name — avoids creating a duplicate for an org someone already
        // added to People before this import ran.
        if (! $org) {
            $org = Person::where('is_organization', true)
                ->whereRaw('LOWER(organization) = ?', [$key])
                ->first();
        }

        if ($org) {
            if ($commit && (! $org->source_system || ! $org->source_ref)) {
                $org->update(['source_system' => 'flowtrac', 'source_ref' => $sourceRef]);
            }

            return $this->orgCache[$key] = $org;
        }

        if (! $commit) {
            return null;
        }

        $org = Person::create([
            'organization' => trim($accountName),
            'is_organization' => true,
            'source_system' => 'flowtrac',
            'source_ref' => $sourceRef,
        ]);

        return $this->orgCache[$key] = $org;
    }
}
