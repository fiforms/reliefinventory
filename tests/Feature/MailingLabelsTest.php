<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Http\Controllers\MailingLabelController;
use App\Models\Person;
use App\Models\Role;
use App\Models\Transaction;

function mailPerson(array $attrs = [], array $roles = []): Person
{
    $person = Person::create($attrs + [
        'first_name' => 'Pat', 'last_name' => 'Doe', 'address' => '1 Main St',
        'city' => 'Statesville', 'state' => 'NC', 'zip' => '28677',
    ]);
    foreach ($roles as $role) {
        $person->roles()->attach(Role::where('name', $role)->value('id'));
    }

    return $person;
}

function mailTransaction(Person $person, string $type): void
{
    Transaction::create([
        'type' => $type, 'person_id' => $person->id, 'order_date' => '2026-10-01',
        'status_id' => Transaction::statusId($type === 'order' ? Transaction::STATUS_NEW_ORDER : Transaction::STATUS_RECEIVED),
    ]);
}

test('the page, list and PDF need manage-people', function () {
    $user = userWithPermissions('view-reports');

    $this->actingAs($user)->get('/reports/mailing-labels')->assertForbidden();
    $this->actingAs($user)->getJson('/json/mailing-labels?audience=partner')->assertForbidden();
    $this->actingAs($user)->get('/report/mailing-labels.pdf?audience=partner')->assertForbidden();
});

test('audiences come from tags or history; other is people with neither', function () {
    $user = userWithPermissions('manage-people');
    $taggedPartner = mailPerson(['first_name' => 'Tagged', 'last_name' => 'Partner'], ['Partner']);
    $orderingPartner = mailPerson(['first_name' => 'Ordering', 'last_name' => 'Partner']);
    mailTransaction($orderingPartner, 'order');
    $taggedDonor = mailPerson(['first_name' => 'Tagged', 'last_name' => 'Donor'], ['Donor']);
    $givingDonor = mailPerson(['first_name' => 'Giving', 'last_name' => 'Donor']);
    mailTransaction($givingDonor, 'donation');
    $volunteer = mailPerson(['first_name' => 'Vol', 'last_name' => 'Unteer', 'is_volunteer' => true]);
    $other = mailPerson(['first_name' => 'Just', 'last_name' => 'Other']);
    mailPerson(['first_name' => 'Staff', 'last_name' => 'Member'], ['Office']);
    mailPerson(['first_name' => 'Train', 'last_name' => 'Ee'])->forceFill(['is_trainee' => true])->save();

    $ids = fn (string $audience) => collect($this->actingAs($user)
        ->getJson("/json/mailing-labels?audience=$audience")->assertOk()->json('records'))->pluck('id')->sort()->values()->all();

    expect($ids('partner'))->toBe([$taggedPartner->id, $orderingPartner->id])
        ->and($ids('donor'))->toBe([$taggedDonor->id, $givingDonor->id])
        ->and($ids('volunteer'))->toBe([$volunteer->id])
        // The staff login (has a role), the trainee, the Unknown Donor and the acting user aren't "other".
        ->and($ids('other'))->toBe(collect([$other->id, $user->id])->sort()->values()->all());
});

test('people without a full address are listed but not mailable', function () {
    $user = userWithPermissions('manage-people');
    mailPerson(['first_name' => 'No', 'last_name' => 'Street', 'address' => null], ['Partner']);

    $record = $this->actingAs($user)->getJson('/json/mailing-labels?audience=partner')->json('records.0');

    expect($record['mailable'])->toBeFalse()->and($record['lines'])->toBe([]);
});

test('label lines are name, organization, street, then city state zip', function () {
    $person = mailPerson(['organization' => 'Hope Pantry', 'address' => "1 Main St\nSuite 2"]);

    expect(MailingLabelController::labelLines($person))
        ->toBe(['Pat Doe', 'Hope Pantry', '1 Main St', 'Suite 2', 'Statesville, NC 28677']);

    $org = mailPerson(['first_name' => null, 'last_name' => null, 'organization' => 'Hope Pantry']);
    expect(MailingLabelController::labelLines($org))->toBe(['Hope Pantry', '1 Main St', 'Statesville, NC 28677']);
});

test('the PDF is Avery 8160: Letter, 30 per sheet, starting where asked, honoring the selection', function () {
    if (pdfToolsMissing()) {
        $this->markTestSkipped('poppler-utils not installed');
    }
    $people = collect(range(1, 31))->map(fn ($i) => mailPerson(
        ['first_name' => 'Person', 'last_name' => sprintf('N%02d', $i)], ['Donor'],
    ));

    $render = function (array $params) {
        $path = tempnam(sys_get_temp_dir(), 'mail').'.pdf';
        app(MailingLabelController::class)->labelsBuilder($params + ['audience' => 'donor'])->save($path);

        return $path;
    };

    $all = $render([]);
    expect(pdfInfo($all))->toMatch('/Pages:\s+2\b/')->toMatch('/Page size:\s+612(\.\d+)? x 792(\.\d+)? pts/')
        ->and(pdfText($all))->toContain('Person N01')->toContain('Person N31')->toContain('Statesville, NC 28677');

    // Starting at label 30 leaves room for one on the first sheet: 1 + 30 = 31 → still 2 sheets; at 2, 29 + 2.
    expect(pdfInfo($render(['start' => 30])))->toMatch('/Pages:\s+2\b/');

    $excluded = $render(['exclude' => [$people[0]->id]]);
    expect(pdfInfo($excluded))->toMatch('/Pages:\s+1\b/')
        ->and(pdfText($excluded))->not->toContain('Person N01');

    $picked = $render(['ids' => [$people[4]->id]]);
    expect(pdfText($picked))->toContain('Person N05')->not->toContain('Person N06');
});

test('the labels can be queued on a Letter printer', function () {
    $user = userWithPermissions('manage-people', 'print-documents');
    $printer = makePrinter(printServer(['office']), 'office', 'report');
    mailPerson([], ['Partner']);

    $this->actingAs($user)->postJson('/json/print', [
        'printer_id' => $printer->id, 'document' => 'mailing-labels',
        'params' => ['audience' => 'partner', 'start' => 3],
    ])->assertSuccessful()->assertJsonPath('title', 'Mailing labels (Partners)');
});
