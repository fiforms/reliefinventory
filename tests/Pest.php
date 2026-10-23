<?php

use App\Models\Category;
use App\Models\County;
use App\Models\DevicePairingCode;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Models\OrderLine;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Printer;
use App\Models\SetupState;
use App\Models\TrainingProduct;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseFromSnapshot;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabaseFromSnapshot::class)
    // A fresh test database has no administrator, which would send every request
    // to the first-run wizard (RedirectToSetup). Treat setup as done unless a test
    // is specifically about the wizard (tests/Feature/SetupWizardTest.php).
    ->beforeEach(fn () => SetupState::current()->update(['completed_at' => now()]))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user granted exactly the given permission keys, via a direct
 * person_permissions override — deliberately not dependent on
 * PermissionsSeeder/role_permissions having been seeded, so each test's
 * access needs are self-contained and explicit rather than inherited from
 * default role grants that could drift.
 */
function userWithPermissions(string ...$keys): User
{
    $user = User::factory()->create();

    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key]);
        $user->person_permissions()->attach($permission->id, ['granted' => true]);
    }

    return $user;
}

// ---- print queue: shared by the Print*Test files ------------------------------------------------

/** A print server written straight to the database, already a print station (no pairing). */
function printServer(array $queues = [], bool $online = true): ManagedDevice
{
    $device = (new ManagedDevice(['name' => 'Print PC', 'kiosk_function' => 'print_station']))->forceFill([
        'device_uuid' => 'print-'.uniqid(),
        'last_seen_at' => $online ? now() : now()->subHour(),
        'reported_printers' => $queues,
    ]);
    $device->save();

    return $device;
}

/** An active, sited printer on $device for the OS queue $queue. */
function makePrinter(ManagedDevice $device, string $queue, string $media = 'label_large', array $extra = []): Printer
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);

    $printer = (new Printer(array_merge([
        'name' => $queue, 'kiosk_location_id' => $location->id, 'media' => $media, 'active' => true,
    ], $extra)))->forceFill([
        'managed_device_id' => $device->id, 'queue_name' => $queue,
    ]);
    $printer->save();

    return $printer;
}

/** A device paired through the real API; returns [device, bearer token]. Its Function is left unset unless given. */
function pairPrintServer(string $uuid = 'ps-1', ?string $function = null): array
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $code = DevicePairingCode::create([
        'code' => (string) random_int(100000, 999999), 'kiosk_location_id' => $location->id, 'expires_at' => now()->addMinutes(5),
    ]);
    $response = test()->postJson('/api/devices/pair', [
        'code' => $code->code, 'device_name' => "Server {$uuid}", 'device_uuid' => $uuid,
    ])->assertCreated();
    $device = ManagedDevice::where('device_uuid', $uuid)->first();
    if ($function) {
        $device->forceFill(['kiosk_function' => $function])->save();
    }

    return [$device, $response->json('token')];
}

// PDF inspection needs poppler-utils (pdfinfo/pdftotext); skip rather than fail where it's absent.
function pdfToolsMissing(): bool
{
    return trim((string) shell_exec('command -v pdfinfo pdftotext | wc -l')) !== '2';
}

function pdfInfo(string $pdf): string
{
    return (string) shell_exec('pdfinfo '.escapeshellarg($pdf));
}

function pdfText(string $pdf): string
{
    return (string) shell_exec('pdftotext -layout '.escapeshellarg($pdf).' -');
}

// Shared by UnfulfilledRequestsReportTest and PartnerReportTest.
function urrItemtype(string $name): ItemType
{
    $unitId = DB::table('units')->where('name', 'Case')->value('id')
        ?? DB::table('units')->insertGetId(['name' => 'Case', 'abbreviation' => 'cs']);

    return ItemType::create([
        'name' => $name, 'unit_id' => $unitId,
        'category_id' => Category::firstOrCreate(['name' => 'Beverages'])->id,
    ]);
}

function urrPartner(?string $county, ?string $zip): Person
{
    $person = Person::create(['organization' => 'Partner '.($zip ?? 'none')]);
    $person->zip = $zip;
    $person->county_id = $county ? County::firstOrCreate(['county' => $county, 'state' => 'WA'])->id : null;
    $person->save();

    return $person;
}

/**
 * An order in $status with one line per [itemtype, requested, filled, approved?].
 */
function urrOrder(Person $partner, string $status, array $lines, string $date = '2026-10-01'): Transaction
{
    $order = Transaction::create([
        'type' => 'order', 'person_id' => $partner->id,
        'status_id' => Transaction::statusId($status), 'order_date' => $date,
    ]);
    $packagetypeId = DB::table('packagetypes')->where('singular', 'Box')->value('id')
        ?? DB::table('packagetypes')->insertGetId(['singular' => 'Box', 'plural' => 'Boxes']);

    foreach ($lines as [$itemtype, $requested, $filled, $approved]) {
        $line = OrderLine::create(['orderdonation_id' => $order->id, 'itemtype_id' => $itemtype->id, 'qty_requested' => $requested]);
        if ($approved !== null) {
            $line->qty_approved = $approved;
            $line->save();
        }
        if ($filled !== null) {
            $item = Item::firstOrCreate(['itemtype_id' => $itemtype->id], ['packagetypes_id' => $packagetypeId, 'description' => $itemtype->name]);
            $order->itemLedgers()->create([
                'item_id' => $item->id, 'order_line_id' => $line->id,
                'qty_subtracted' => $filled, 'disposition' => 'usable',
            ]);
        }
    }

    return $order;
}

// Training Mode's scanned product list — shared by TrainingPreparerTest and TrainingReportTest.
/** $products distinct scanned products over three item types, $each units of each. Returns the item rows. */
function scannedProducts(int $products = 15, int $each = 8): array
{
    $packagetypeId = DB::table('packagetypes')->value('id') ?? DB::table('packagetypes')->insertGetId(['singular' => 'Can', 'plural' => 'Cans']);
    $types = collect(['Canned food', 'Snacks', 'Electronics'])->map(function ($name, $i) {
        $type = urrItemtype($name);
        $type->forceFill(['family' => sprintf('%04d', 901 + $i), 'variant' => '00', 'active' => true])->save();

        return $type;
    });
    $items = [];
    foreach (range(1, $products) as $n) {
        $item = $types[$n % 3]->items()->create(['packagetypes_id' => $packagetypeId, 'pluscode' => '0000', 'size' => 1, 'case_qty' => 1,
            'active' => true, 'description' => "Product $n"]);
        TrainingProduct::create(['item_id' => $item->id, 'qty' => $each]);
        $items[] = $item;
    }

    return $items;
}
