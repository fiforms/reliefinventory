<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

use App\Models\Person;
use App\Models\Role;
use App\Support\UsStates;
use League\Csv\Reader;

/**
 * A plain names-and-addresses CSV (e.g. a partner or donor mailing list kept
 * in a spreadsheet) onto People, tagged so the Mailing Labels page can find
 * them. Columns are matched by header, case and punctuation ignored:
 *
 *   First Name, Last Name (or one Name column), Organization, Address,
 *   Address 2, City, State, Zip, Tags
 *
 * Tags (several allowed, separated by , ; / or |): donor, partner, volunteer,
 * other. Donor and Partner attach those roles (they grant no permissions);
 * Volunteer sets is_volunteer — never the Volunteer role, which does grant
 * permissions; Other, or a blank, adds nothing.
 *
 * A row matches an existing person by its own earlier import (source_ref),
 * else by the same name — or organization, for a row with no personal name
 * — and the same 5-digit zip. A match gets its address replaced by the row's
 * (blank cells leave a field alone) and the row's tags added; tags are never
 * removed. A name that matches more than one person is an error rather than
 * a guess.
 */
class MailingListImporter implements Importer
{
    public const SOURCE = 'mailing_list';

    /** Tag word → role name; volunteer and other are handled separately. */
    private const ROLE_TAGS = ['donor' => 'Donor', 'partner' => 'Partner'];

    private const TAGS = ['donor', 'partner', 'volunteer', 'other'];

    /** Normalized header → field. */
    private const HEADERS = [
        'firstname' => 'first_name', 'first' => 'first_name', 'givenname' => 'first_name',
        'lastname' => 'last_name', 'last' => 'last_name', 'surname' => 'last_name', 'familyname' => 'last_name',
        'name' => 'name', 'fullname' => 'name', 'contactname' => 'name',
        'organization' => 'organization', 'organisation' => 'organization', 'org' => 'organization',
        'company' => 'organization', 'church' => 'organization', 'agency' => 'organization',
        'address' => 'address', 'address1' => 'address', 'addressline1' => 'address', 'street' => 'address',
        'streetaddress' => 'address', 'mailingaddress' => 'address',
        'address2' => 'address2', 'addressline2' => 'address2', 'apt' => 'address2', 'suite' => 'address2',
        'unit' => 'address2',
        'city' => 'city', 'town' => 'city',
        'state' => 'state', 'st' => 'state', 'province' => 'state',
        'zip' => 'zip', 'zipcode' => 'zip', 'postalcode' => 'zip', 'postcode' => 'zip', 'zip5' => 'zip',
        'tag' => 'tags', 'tags' => 'tags', 'type' => 'tags', 'category' => 'tags', 'list' => 'tags',
    ];

    public ?int $actorId = null;

    public function source(): string
    {
        return self::SOURCE;
    }

    public function fileType(): string
    {
        return 'mailing_list';
    }

    public function process(string $absolutePath, bool $commit): ImportRunResult
    {
        $result = new ImportRunResult;

        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);
        $csv->skipEmptyRecords();

        $fields = [];
        foreach ($csv->getHeader() as $header) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $header));
            if (isset(self::HEADERS[$key]) && ! in_array(self::HEADERS[$key], $fields, true)) {
                $fields[$header] = self::HEADERS[$key];
            }
        }
        if (! array_intersect(['first_name', 'last_name', 'name', 'organization'], $fields)) {
            $result->flagDecision('No name column found. The first row must be headings such as First Name, Last Name, Organization, Address, City, State, Zip, Tags.');

            return $result;
        }
        if (! in_array('address', $fields, true)) {
            $result->flagDecision('No Address column found — these people can be imported but won\'t get a mailing label.');
        }

        $roleIds = Role::whereIn('name', array_values(self::ROLE_TAGS))->pluck('id', 'name');
        // Rows already handled in this file, by match key → person id (or 0 in a preview), so a
        // repeated row merges into the first rather than making a second person.
        $seen = [];
        $noAddress = 0;

        $rowNumber = 0;
        foreach ($csv->getRecords() as $raw) {
            $rowNumber++;
            $row = [];
            foreach ($fields as $header => $field) {
                $row[$field] = trim((string) ($raw[$header] ?? ''));
            }
            $row += array_fill_keys(['first_name', 'last_name', 'name', 'organization', 'address', 'address2', 'city', 'state', 'zip', 'tags'], '');

            if ($row['first_name'] === '' && $row['last_name'] === '' && $row['name'] !== '') {
                $parts = preg_split('/\s+/', $row['name'], 2);
                $row['first_name'] = $parts[0];
                $row['last_name'] = $parts[1] ?? '';
            }
            $label = trim($row['first_name'].' '.$row['last_name']) ?: $row['organization'];
            if ($label === '') {
                if (implode('', $row) !== '') {
                    $result->addRow($rowNumber, null, 'error', 'No name or organization.', $raw);
                }

                continue;
            }

            $tags = [];
            foreach (preg_split('/[,;\/|]+/', strtolower($row['tags'])) as $tag) {
                $tag = rtrim(trim($tag), 's');
                if ($tag === '') {
                    continue;
                }
                if (! in_array($tag, self::TAGS, true)) {
                    $result->addRow($rowNumber, $label, 'error', "Unknown tag \"$tag\" — use donor, partner, volunteer or other.", $raw);

                    continue 2;
                }
                $tags[$tag] = true;
            }

            $state = null;
            if ($row['state'] !== '') {
                $state = UsStates::code($row['state']);
                if ($state === null) {
                    $result->addRow($rowNumber, $label, 'error', "Unrecognized state \"{$row['state']}\".", $raw);

                    continue;
                }
            }

            $zip = $row['zip'];
            // A spreadsheet drops a zip's leading zero (02134 → 2134).
            if (preg_match('/^\d{3,4}$/', $zip)) {
                $zip = str_pad($zip, 5, '0', STR_PAD_LEFT);
            }
            if ($zip !== '' && ! preg_match('/^\d{5}(-?\d{4})?$/', $zip)) {
                $result->addRow($rowNumber, $label, 'error', "Zip \"{$row['zip']}\" isn't a 5- or 9-digit zip code.", $raw);

                continue;
            }

            $address = implode(', ', array_filter([$row['address'], $row['address2']], fn ($v) => $v !== ''));
            if ($address === '' || $row['city'] === '' || $state === null || $zip === '') {
                $noAddress++;
            }

            $zip5 = substr($zip, 0, 5);
            $hasName = trim($row['first_name'].$row['last_name']) !== '';
            $sourceRef = 'mailing:'.sha1(strtolower(($hasName ? $row['first_name'].'|'.$row['last_name'] : '|'.$row['organization']).'|'.$zip5.'|'.($zip5 === '' ? $address : '')));
            $sourceKey = $label.($zip5 !== '' ? ', '.$zip5 : '');

            $existing = $this->findExisting($row, $hasName, $zip5, $sourceRef, $seen);
            if ($existing === false) {
                $result->addRow($rowNumber, $sourceKey, 'error', 'Matches more than one person with this name and zip — update them on the People page instead.', $raw);

                continue;
            }
            $outcome = ($existing || isset($seen[$sourceRef])) ? 'updated' : 'created';

            if (! $commit) {
                $seen[$sourceRef] = $existing?->id ?? 0;
                $result->addRow($rowNumber, $sourceKey, $outcome, null, $raw, 'Person', $existing?->id);

                continue;
            }

            $attributes = array_filter([
                'address' => $address,
                'city' => $row['city'],
                'state' => $state,
                'zip' => $zip,
            ], fn ($v) => $v !== null && $v !== '');
            if (isset($tags['volunteer'])) {
                $attributes['is_volunteer'] = true;
            }

            try {
                if (! $existing) {
                    $existing = Person::create($attributes + [
                        'first_name' => $row['first_name'] ?: null,
                        'last_name' => $row['last_name'] ?: null,
                        'organization' => $row['organization'] ?: null,
                        'is_organization' => ! $hasName,
                        'source_system' => self::SOURCE,
                        'source_ref' => $sourceRef,
                    ]);
                    if ($this->actorId && ! $existing->created_by_person_id) {
                        $existing->forceFill(['created_by_person_id' => $this->actorId])->save();
                    }
                } else {
                    if ($existing->organization === null && $row['organization'] !== '') {
                        $attributes['organization'] = $row['organization'];
                    }
                    $existing->update($attributes);
                }

                $attach = [];
                foreach (self::ROLE_TAGS as $tag => $roleName) {
                    if (isset($tags[$tag]) && isset($roleIds[$roleName])) {
                        $attach[] = $roleIds[$roleName];
                    }
                }
                if ($attach) {
                    $existing->roles()->syncWithoutDetaching($attach);
                }

                $seen[$sourceRef] = $existing->id;
                $result->addRow($rowNumber, $sourceKey, $outcome, null, $raw, 'Person', $existing->id);
            } catch (\Throwable $e) {
                $result->addRow($rowNumber, $sourceKey, 'error', $e->getMessage(), $raw);
            }
        }

        if ($noAddress) {
            $result->flagDecision("$noAddress row".($noAddress === 1 ? ' has' : 's have').' no complete mailing address (street, city, state and zip) — they will be imported but won\'t get a mailing label.');
        }

        return $result;
    }

    /**
     * The person this row updates: null for a new person, false when the name matches
     * more than one person (ambiguous).
     *
     * @param  array<string, int>  $seen
     */
    private function findExisting(array $row, bool $hasName, string $zip5, string $sourceRef, array $seen): Person|null|false
    {
        if (! empty($seen[$sourceRef])) {
            return Person::find($seen[$sourceRef]);
        }

        $bySource = Person::where('source_system', self::SOURCE)->where('source_ref', $sourceRef)->first();
        if ($bySource) {
            return $bySource;
        }
        if ($zip5 === '') {
            return null;
        }

        $query = Person::whereNull('system_key')->where('is_trainee', false)->where('zip', 'like', $zip5.'%');
        if ($hasName) {
            $query->whereRaw("LOWER(TRIM(COALESCE(first_name, ''))) = ?", [strtolower($row['first_name'])])
                ->whereRaw("LOWER(TRIM(COALESCE(last_name, ''))) = ?", [strtolower($row['last_name'])]);
        } else {
            $query->whereRaw("LOWER(TRIM(COALESCE(first_name, ''))) = ''")
                ->whereRaw("LOWER(TRIM(COALESCE(last_name, ''))) = ''")
                ->whereRaw('LOWER(TRIM(organization)) = ?', [strtolower($row['organization'])]);
        }
        $matches = $query->limit(2)->get();

        return match ($matches->count()) {
            0 => null,
            1 => $matches->first(),
            default => false,
        };
    }
}
