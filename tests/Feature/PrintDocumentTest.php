<?php

use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Models\Pallet;
use App\Models\Person;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Transaction;
use App\Services\Printing\DocumentRegistry;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

// A typed, active, sited printer on a print server whose last heartbeat is fresh.
function docPrinter(string $media = 'report', array $overrides = [], bool $online = true): Printer
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $device = (new ManagedDevice(['name' => 'Server', 'kiosk_function' => 'print_station']))->forceFill([
        'device_uuid' => 'doc-'.uniqid(),
        'last_seen_at' => $online ? now() : now()->subHour(),
        'reported_printers' => [['queue_name' => 'Q', 'state' => 'idle']],
    ]);
    $device->save();

    $printer = (new Printer(array_merge([
        'name' => 'Office', 'kiosk_location_id' => $location->id, 'media' => $media, 'active' => true,
    ], $overrides)))->forceFill(['managed_device_id' => $device->id, 'queue_name' => 'Q']);
    $printer->save();

    return $printer;
}

function printAs($user, string $document, Printer $printer, array $params = [], array $extra = [])
{
    return test()->actingAs($user)->postJson('/json/print', array_merge([
        'document' => $document, 'params' => $params, 'printer_id' => $printer->id,
    ], $extra));
}

beforeEach(function () {
    Pdf::fake();
    Storage::fake(config('printing.disk'));
});

test('every parameterless report renders through the registry and queues a job', function (string $document, string $permission) {
    $printer = docPrinter('report');
    $user = userWithPermissions('print-documents', $permission);

    $response = printAs($user, $document, $printer)->assertCreated();

    $job = PrintJob::findOrFail($response->json('id'));
    expect($job->status)->toBe('queued')
        ->and($job->printer_id)->toBe($printer->id)
        ->and($job->media)->toBe('report')
        ->and($job->document)->toBe($document)
        ->and($job->requested_by)->toBe($user->id)
        ->and($job->kiosk_location_id)->toBe($printer->kiosk_location_id);
    Storage::disk(config('printing.disk'))->assertExists($job->pdf_path);
    expect($job->statusLogs()->pluck('to_status')->all())->toBe(['queued']);
})->with([
    ['inventory-report', 'view-reports'],
    ['outstanding-orders', 'view-reports'],
    ['unfulfilled-requests', 'view-reports'],
    ['sitrep', 'view-sitrep'],
    ['order-form', 'manage-orders'],
    ['help-receiving', 'general-access'],
    ['help-donation-offers', 'general-access'],
    ['help-sorting', 'general-access'],
    ['help-inventory-movement', 'general-access'],
    ['help-order-entry', 'general-access'],
    ['help-order-filling', 'general-access'],
    ['help-shipping', 'general-access'],
    ['help-items', 'general-access'],
    ['help-people', 'general-access'],
]);

test('a pallet label is queued on a large-label printer', function () {
    $printer = docPrinter('label_large');
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);

    printAs(userWithPermissions('print-documents', 'manage-pallets'), 'pallet-label', $printer, ['id' => $pallet->id])
        ->assertCreated();

    expect(PrintJob::first()->media)->toBe('label_large');
});

test('preprint labels only include unassigned R pallets', function () {
    $printer = docPrinter('label_large');
    $free = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);

    printAs(userWithPermissions('print-documents', 'manage-receiving'), 'preprint-pallet-labels', $printer, ['ids' => [$free->id]])
        ->assertCreated();
    printAs(userWithPermissions('print-documents', 'manage-receiving'), 'preprint-pallet-labels', $printer, ['ids' => [999999]])
        ->assertNotFound();
});

test('a document only goes to a printer with matching media', function () {
    $labelPrinter = docPrinter('label_large');
    $reportPrinter = docPrinter('report');
    $user = userWithPermissions('print-documents', 'view-reports', 'manage-pallets');
    $pallet = Pallet::create(['kind' => 'R', 'status' => 'received', 'datepacked' => now()->toDateString()]);

    printAs($user, 'inventory-report', $labelPrinter)->assertUnprocessable()->assertJsonValidationErrors('printer_id');
    printAs($user, 'pallet-label', $reportPrinter, ['id' => $pallet->id])->assertUnprocessable()->assertJsonValidationErrors('printer_id');

    expect(PrintJob::count())->toBe(0);
});

test('inactive, untyped, and unsited printers are refused', function () {
    $user = userWithPermissions('print-documents', 'view-reports');

    printAs($user, 'inventory-report', docPrinter('report', ['active' => false]))->assertUnprocessable();
    printAs($user, 'inventory-report', docPrinter('report', ['media' => null]))->assertUnprocessable();
    printAs($user, 'inventory-report', docPrinter('report', ['kiosk_location_id' => null]))->assertUnprocessable();
    expect(PrintJob::count())->toBe(0);
});

test('printing needs print-documents and the document\'s own permission', function () {
    $printer = docPrinter('report');

    printAs(userWithPermissions('view-reports'), 'inventory-report', $printer)->assertForbidden();
    printAs(userWithPermissions('print-documents'), 'inventory-report', $printer)->assertForbidden();

    expect(PrintJob::count())->toBe(0);
});

test('printing requires a signed-in user', function () {
    $this->postJson('/json/print', ['document' => 'inventory-report', 'printer_id' => docPrinter('report')->id])
        ->assertUnauthorized();
});

test('unknown documents and bad params are validation errors', function () {
    $printer = docPrinter('report');
    $user = userWithPermissions('print-documents', 'manage-orders');

    printAs($user, 'nope', $printer)->assertUnprocessable()->assertJsonValidationErrors('document');
    printAs($user, 'bol', $printer, [])->assertUnprocessable();
    printAs($user, 'pick-sheets', $printer, ['ids' => []])->assertUnprocessable();
    printAs($user, 'inventory-report', $printer, [], ['copies' => 0])->assertUnprocessable();
});

test('a builder abort surfaces its status and leaves no job or file behind', function () {
    $printer = docPrinter('report');

    printAs(userWithPermissions('print-documents', 'manage-orders'), 'bol', $printer, ['id' => 999999])->assertNotFound();

    expect(PrintJob::count())->toBe(0)
        ->and(Storage::disk(config('printing.disk'))->allFiles())->toBe([]);
});

test('copies are stored and an offline server still accepts the job with a warning', function () {
    $printer = docPrinter('report', [], online: false);

    $response = printAs(userWithPermissions('print-documents', 'view-reports'), 'inventory-report', $printer, [], ['copies' => 3])
        ->assertCreated();

    expect($response->json('printer.ready'))->toBeFalse()
        ->and($response->json('printer.status'))->toBe('unavailable')
        ->and($response->json('message'))->toContain('it will print when');
    expect(PrintJob::first()->copies)->toBe(3);
});

test('requested_by is the signed-in user, never request input', function () {
    $printer = docPrinter('report');
    $user = userWithPermissions('print-documents', 'view-reports');
    $other = userWithPermissions('general-access');

    printAs($user, 'inventory-report', $printer, [], ['requested_by' => $other->id])->assertCreated();

    expect(PrintJob::first()->requested_by)->toBe($user->id);
});

test('every registry document declares a known media and a title', function () {
    foreach ((new DocumentRegistry)->all() as $key => $definition) {
        expect(config("printing.media.{$definition['media']}"))->not->toBeNull("{$key} media")
            ->and($definition['title'])->toBeInstanceOf(Closure::class);
    }
});

function docOrder(string $status): Transaction
{
    return Transaction::create([
        'type' => 'order',
        'person_id' => Person::create(['first_name' => 'Pat', 'last_name' => 'Rivera'])->id,
        'status_id' => Transaction::statusId($status),
        'order_date' => now()->toDateString(),
        'fulfillment_method' => 'delivery',
    ]);
}

test('a BOL for a filled order is queued on a report printer', function () {
    $order = docOrder(Transaction::STATUS_FILLED);

    printAs(userWithPermissions('print-documents', 'manage-orders'), 'bol', docPrinter('report'), ['id' => $order->id])
        ->assertCreated();

    expect(PrintJob::first()->title)->toBe("BOL for order #{$order->id}")
        ->and($order->fresh()->bol_number)->not->toBeNull();
});

test('a BOL for an order that is not yet filled is refused with 409 and queues nothing', function () {
    $order = docOrder(Transaction::STATUS_NEW_ORDER);

    printAs(userWithPermissions('print-documents', 'manage-orders'), 'bol', docPrinter('report'), ['id' => $order->id])
        ->assertStatus(409);

    expect(PrintJob::count())->toBe(0);
});

test('pick sheets for several orders are queued as one job', function () {
    $a = docOrder(Transaction::STATUS_FILLING);
    $b = docOrder(Transaction::STATUS_FILLING);

    printAs(userWithPermissions('print-documents', 'manage-orders'), 'pick-sheets', docPrinter('report'), ['ids' => [$a->id, $b->id]])
        ->assertCreated();

    expect(PrintJob::first()->title)->toBe('Pick sheets (2 orders)');
});

test('the printer list offers only usable printers, filtered by media, with status', function () {
    $report = docPrinter('report', ['name' => 'Office']);
    docPrinter('label_large', ['name' => 'Front Gate']);
    docPrinter('report', ['name' => 'Retired', 'active' => false]);
    docPrinter('report', ['name' => 'Untyped', 'media' => null]);
    docPrinter('report', ['name' => 'Down'], online: false);

    $user = userWithPermissions('print-documents');

    $reports = $this->actingAs($user)->getJson('/json/printers?media=report')->assertOk()->json('printers');
    expect(collect($reports)->pluck('name')->sort()->values()->all())->toBe(['Down', 'Office'])
        ->and(collect($reports)->firstWhere('name', 'Office'))->toMatchArray([
            'id' => $report->id, 'media' => 'report', 'status' => 'idle', 'ready' => true,
        ])
        ->and(collect($reports)->firstWhere('name', 'Down'))->toMatchArray(['status' => 'unavailable', 'ready' => false]);

    $all = $this->actingAs($user)->getJson('/json/printers')->assertOk()->json('printers');
    expect($all)->toHaveCount(3);
});

test('the printer list rejects unknown media and requires print-documents', function () {
    $this->actingAs(userWithPermissions('print-documents'))->getJson('/json/printers?media=bogus')->assertUnprocessable();
    $this->actingAs(userWithPermissions('general-access'))->getJson('/json/printers')->assertForbidden();
});
