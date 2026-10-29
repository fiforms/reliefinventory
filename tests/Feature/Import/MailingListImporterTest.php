<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;
use App\Services\Import\MailingListImporter;

function writeMailingFixture(array $rows, array $header = ['First Name', 'Last Name', 'Organization', 'Address', 'City', 'State', 'Zip', 'Tags']): string
{
    $path = tempnam(sys_get_temp_dir(), 'mailing_').'.csv';
    $lines = [implode(',', $header)];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

function importedPeople()
{
    return Person::whereNull('system_key');
}

test('preview reports what would happen without writing anything', function () {
    $path = writeMailingFixture([
        ['Ann', 'Lee', '', '1 Main St', 'Statesville', 'NC', '28677', 'donor'],
        ['', '', 'Hope Pantry', '9 Oak Ave', 'Statesville', 'NC', '28625', 'partner'],
    ]);

    $result = (new MailingListImporter)->process($path, false);

    expect($result->created)->toBe(2)->and($result->errored)->toBe(0)
        ->and(importedPeople()->count())->toBe(0);
});

test('commit creates people, tags donors and partners by role and volunteers by flag', function () {
    $path = writeMailingFixture([
        ['Ann', 'Lee', '', '1 Main St', 'Statesville', 'North Carolina', '28677', 'Donor'],
        ['', '', 'Hope Pantry', '9 Oak Ave', 'Statesville', 'nc', '28625', 'partners'],
        ['Bo', 'Diaz', 'Grace Church', '5 Elm St', 'Mooresville', 'NC', '28115', 'donor, partner; volunteer'],
        ['Cy', 'Ng', '', '7 Pine Rd', 'Troutman', 'NC', '28166', ''],
    ]);

    $result = (new MailingListImporter)->process($path, true);
    expect($result->created)->toBe(4)->and($result->errored)->toBe(0);

    $ann = Person::where('first_name', 'Ann')->first();
    expect($ann->state)->toBe('NC')
        ->and($ann->roles->pluck('name')->all())->toBe(['Donor'])
        ->and($ann->is_volunteer)->toBeFalse()
        ->and($ann->source_system)->toBe('mailing_list');

    $pantry = Person::where('organization', 'Hope Pantry')->first();
    expect($pantry->is_organization)->toBeTrue()
        ->and($pantry->first_name)->toBeNull()
        ->and($pantry->roles->pluck('name')->all())->toBe(['Partner']);

    $bo = Person::where('first_name', 'Bo')->first();
    expect($bo->roles->pluck('name')->sort()->values()->all())->toBe(['Donor', 'Partner'])
        ->and($bo->is_volunteer)->toBeTrue()
        // Volunteer is a flag, never the Volunteer role (which grants permissions).
        ->and($bo->hasRole('Volunteer'))->toBeFalse();

    expect(Person::where('first_name', 'Cy')->first()->roles)->toHaveCount(0);
});

test('re-importing the same file updates instead of duplicating', function () {
    $path = writeMailingFixture([
        ['Ann', 'Lee', '', '1 Main St', 'Statesville', 'NC', '28677', 'donor'],
    ]);
    (new MailingListImporter)->process($path, true);
    $again = (new MailingListImporter)->process($path, true);

    expect($again->created)->toBe(0)->and($again->updated)->toBe(1)
        ->and(importedPeople()->count())->toBe(1);
});

test('a person already in People with the same name and zip gets the new address and tag', function () {
    $existing = Person::create(['first_name' => 'Ann', 'last_name' => 'Lee', 'zip' => '28677', 'phone' => '555-0100']);

    $path = writeMailingFixture([
        ['ann', 'LEE', '', '22 New Rd', 'Statesville', 'NC', '28677-1234', 'partner'],
    ]);
    $result = (new MailingListImporter)->process($path, true);

    $existing->refresh();
    expect($result->updated)->toBe(1)
        ->and(importedPeople()->count())->toBe(1)
        ->and($existing->address)->toBe('22 New Rd')
        ->and($existing->zip)->toBe('28677-1234')
        ->and($existing->phone)->toBe('555-0100')
        ->and($existing->hasRole('Partner'))->toBeTrue();
});

test('a name matching two people is an error, not a guess', function () {
    Person::create(['first_name' => 'Ann', 'last_name' => 'Lee', 'zip' => '28677']);
    Person::create(['first_name' => 'Ann', 'last_name' => 'Lee', 'zip' => '28677']);

    $result = (new MailingListImporter)->process(writeMailingFixture([
        ['Ann', 'Lee', '', '1 Main St', 'Statesville', 'NC', '28677', 'donor'],
    ]), true);

    expect($result->errored)->toBe(1)->and(importedPeople()->count())->toBe(2);
});

test('bad tags, states and zips are reported per row; the rest still import', function () {
    $result = (new MailingListImporter)->process(writeMailingFixture([
        ['Ann', 'Lee', '', '1 Main St', 'Statesville', 'NC', '28677', 'sponsor'],
        ['Bo', 'Diaz', '', '5 Elm St', 'Mooresville', 'Narnia', '28115', 'donor'],
        ['Cy', 'Ng', '', '7 Pine Rd', 'Troutman', 'NC', 'ABCDE', 'donor'],
        ['Di', 'Fox', '', '3 Ash Ln', 'Boston', 'MA', '2134', 'donor'],
        ['', '', '', '8 Nowhere', 'Troutman', 'NC', '28166', 'donor'],
    ]), true);

    expect($result->errored)->toBe(4)->and($result->created)->toBe(1)
        ->and(collect($result->rows)->pluck('error_message')->filter()->implode(' | '))
        ->toContain('Unknown tag "sponsor"')->toContain('Unrecognized state "Narnia"')
        ->toContain('Zip "ABCDE"')->toContain('No name or organization');
    // A spreadsheet's dropped leading zero is put back.
    expect(Person::where('first_name', 'Di')->value('zip'))->toBe('02134');
});

test('headers are matched loosely, a single Name column is split, Address 2 is appended', function () {
    $path = writeMailingFixture(
        [['Ann Marie Lee', 'Hope Pantry', '1 Main St', 'Apt 4', 'Statesville', 'NC', '28677', 'partner']],
        ["\u{FEFF}Full Name", 'Company', 'Street Address', 'Address Line 2', 'City', 'ST', 'Zip Code', 'Type'],
    );
    (new MailingListImporter)->process($path, true);

    $p = Person::where('first_name', 'Ann')->first();
    expect($p->last_name)->toBe('Marie Lee')
        ->and($p->organization)->toBe('Hope Pantry')
        ->and($p->is_organization)->toBeFalse()
        ->and($p->address)->toBe('1 Main St, Apt 4')
        ->and($p->hasRole('Partner'))->toBeTrue();
});

test('rows without a complete address are imported and flagged for review', function () {
    $result = (new MailingListImporter)->process(writeMailingFixture([
        ['Ann', 'Lee', '', '', 'Statesville', 'NC', '28677', 'donor'],
    ]), false);

    expect($result->created)->toBe(1)
        ->and($result->decisions[0] ?? '')->toContain('no complete mailing address');
});

test('a file with no name column is refused with an explanation', function () {
    $result = (new MailingListImporter)->process(writeMailingFixture([['1 Main St']], ['Address']), false);

    expect($result->rows)->toBe([])->and($result->decisions[0])->toContain('No name column');
});

test('the importer is offered on Data Import', function () {
    $user = userWithPermissions('manage-import');

    $this->actingAs($user)->getJson('/json/imports/options')->assertOk()
        ->assertJsonPath('file_types.mailing_list', 'Names & addresses (mailing list) .csv');
});
