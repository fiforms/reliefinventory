<?php

use App\Models\ItemLedger;
use App\Models\Person;
use App\Models\Transaction;
use App\Services\Import\FlowtracDonationsReceivedImporter;
use Illuminate\Support\Facades\DB;

// Fixture rows are drawn from real docs/flowtrac/datadumps/Menus___Quick___
// Donations_Received*.csv content, per the "small fixture CSVs built from
// real sample rows" verification requirement — not the full file.
function writeDonationsFixture(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'flowtrac_donations_').'.csv';
    $lines = ['Date,Reference,Account,Product,Description,Qty,UOM,Status'];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

beforeEach(function () {
    // PackageType has no timestamp columns (see the packagetypes migration)
    // and its model doesn't declare $timestamps = false — PackageTypesSeeder
    // itself works around this with a raw insert, so tests do the same
    // rather than fighting a pre-existing, out-of-scope model quirk.
    DB::table('packagetypes')->insert([
        ['singular' => 'Each', 'plural' => 'Each'],
        ['singular' => 'Bag', 'plural' => 'Bags'],
    ]);
});

test('a donation row with a blank Account is flagged donor_identification_pending against Unknown Donor', function () {
    $path = writeDonationsFixture([
        ['2026-08-19 15:56:47-07', '', '', '532-E', 'Throws/Afghans', '1', 'each', 'Valid'],
    ]);

    (new FlowtracDonationsReceivedImporter)->process($path, true);

    $unknownDonor = Person::where('system_key', 'unknown-donor')->firstOrFail();
    $donation = Transaction::where('source_system', 'flowtrac')->firstOrFail();

    expect($donation->donor_identification_pending)->toBeTrue()
        ->and($donation->person_id)->toBe($unknownDonor->id)
        ->and($donation->status->name)->toBe(Transaction::STATUS_COMPLETE);

    expect(ItemLedger::where('orderdonation_id', $donation->id)->exists())->toBeTrue();
});

test('a donation row with a known Account links to that org, not Unknown Donor', function () {
    $path = writeDonationsFixture([
        ['2026-08-17 10:00:00-07', '', 'Walmart', '020-Bg', 'Cat Food', '2', 'bag', 'Valid'],
    ]);

    (new FlowtracDonationsReceivedImporter)->process($path, true);

    $donation = Transaction::where('source_system', 'flowtrac')->firstOrFail();
    $org = Person::where('organization', 'Walmart')->where('is_organization', true)->firstOrFail();

    expect($donation->donor_identification_pending)->toBeFalse()
        ->and($donation->person_id)->toBe($org->id);
});

test('committing the same file twice does not duplicate donations or ledger entries', function () {
    $path = writeDonationsFixture([
        ['2026-08-19 15:56:47-07', '', '', '532-E', 'Throws/Afghans', '1', 'each', 'Valid'],
    ]);

    (new FlowtracDonationsReceivedImporter)->process($path, true);
    (new FlowtracDonationsReceivedImporter)->process($path, true);

    expect(Transaction::where('source_system', 'flowtrac')->count())->toBe(1)
        ->and(ItemLedger::count())->toBe(1);
});

test('two identical Date+Product rows in the same file get distinct dedup keys, not collapsed', function () {
    $path = writeDonationsFixture([
        ['2026-08-19 15:56:47-07', '', '', '532-E', 'Throws/Afghans', '1', 'each', 'Valid'],
        ['2026-08-19 15:56:47-07', '', '', '532-E', 'Throws/Afghans', '1', 'each', 'Valid'],
    ]);

    (new FlowtracDonationsReceivedImporter)->process($path, true);

    expect(Transaction::where('source_system', 'flowtrac')->count())->toBe(2);
});
