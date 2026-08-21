<?php

use App\Models\Person;
use App\Services\Import\FlowtracContactsImporter;

// Fixture rows are drawn from real docs/flowtrac/datadumps/Contacts1787246374.csv
// content, per the "small fixture CSVs built from real sample rows" verification
// requirement — not the full file.
function writeContactsFixture(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'flowtrac_contacts_').'.csv';
    $lines = ['Account,Name,Office,Cell,Email,Active,B-Default,S-Default'];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

test('preview reports proposed creates without writing anything', function () {
    $path = writeContactsFixture([
        ['Macedonia SDA CHURCH', 'Aaaron Swann', '', '772 464-1119', '', 'Active', 'Yes', 'No'],
    ]);

    $result = (new FlowtracContactsImporter)->process($path, false);

    // One ImportBatchRow per source row — the org lookup/creation is
    // implicit within processing the contact's row, not reported separately.
    // Person::count() isn't 0 here — the "Unknown Donor" system record is
    // seeded by migration in every environment, this import just shouldn't
    // add anything to it.
    expect($result->created)->toBe(1)
        ->and(Person::where('system_key', 'unknown-donor')->count())->toBe(1)
        ->and(Person::whereNull('system_key')->count())->toBe(0);
});

test('commit synthesizes the org Person from the Account column when no Accounts.csv row exists', function () {
    $path = writeContactsFixture([
        ['Macedonia SDA CHURCH', 'Aaaron Swann', '', '772 464-1119', '', 'Active', 'Yes', 'No'],
    ]);

    (new FlowtracContactsImporter)->process($path, true);

    $org = Person::where('is_organization', true)->where('organization', 'Macedonia SDA CHURCH')->first();
    $contact = Person::where('first_name', 'Aaaron')->where('last_name', 'Swann')->first();

    expect($org)->not->toBeNull()
        ->and($org->source_system)->toBe('flowtrac')
        ->and($contact)->not->toBeNull()
        ->and($contact->parent_person_id)->toBe($org->id)
        ->and($contact->contact_role)->toBe('Primary');
});

test('a second contact under the same account reuses the already-created org, not a duplicate', function () {
    $firstPath = writeContactsFixture([
        ['Pine Island Chapter American Legion', 'Aaron Barreda', '', '', '', 'Active', 'Yes', 'No'],
    ]);
    (new FlowtracContactsImporter)->process($firstPath, true);

    $secondPath = writeContactsFixture([
        ['Pine Island Chapter American Legion', 'Second Contact', '', '', '', 'Active', 'No', 'Yes'],
    ]);
    // A fresh importer instance, mirroring how each upload gets a new
    // instance via ImporterRegistry — the org must be found via
    // source_ref/name, not an in-memory cache surviving between runs.
    (new FlowtracContactsImporter)->process($secondPath, true);

    expect(Person::where('is_organization', true)->where('organization', 'Pine Island Chapter American Legion')->count())->toBe(1)
        ->and(Person::where('last_name', 'Barreda')->exists())->toBeTrue()
        ->and(Person::where('first_name', 'Second')->first()->contact_role)->toBe('Shipping Default');
});

test('committing the same file twice does not duplicate contacts (idempotent re-import)', function () {
    $path = writeContactsFixture([
        ['Macedonia SDA CHURCH', 'Aaaron Swann', '', '772 464-1119', '', 'Active', 'Yes', 'No'],
    ]);

    (new FlowtracContactsImporter)->process($path, true);
    (new FlowtracContactsImporter)->process($path, true);

    expect(Person::where('first_name', 'Aaaron')->where('last_name', 'Swann')->count())->toBe(1)
        ->and(Person::where('is_organization', true)->count())->toBe(1);
});

test('B-Default and S-Default are not treated as mutually exclusive', function () {
    $path = writeContactsFixture([
        ['Test Org', 'Both Defaults', '', '', '', 'Active', 'Yes', 'Yes'],
    ]);

    (new FlowtracContactsImporter)->process($path, true);

    expect(Person::where('first_name', 'Both')->first()->contact_role)->toBe('Primary, Shipping Default');
});
