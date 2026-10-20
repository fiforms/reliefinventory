<?php

use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\PrintQueueService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

function mgmtPrinter(string $name = 'Office', string $media = 'report', array $overrides = [], ?ManagedDevice $device = null): Printer
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $device ??= (new ManagedDevice(['name' => 'Server', 'kiosk_function' => 'print_station']))->forceFill([
        'device_uuid' => 'mgmt-'.uniqid(), 'last_seen_at' => now(),
        'reported_printers' => [['queue_name' => $name, 'state' => 'idle']],
    ]);
    $device->save();

    $printer = (new Printer(array_merge([
        'name' => $name, 'kiosk_location_id' => $location->id, 'media' => $media, 'active' => true,
    ], $overrides)))->forceFill(['managed_device_id' => $device->id, 'queue_name' => $name]);
    $printer->save();

    return $printer;
}

function mgmtJob(Printer $printer, array $overrides = [], ?int $requestedBy = null): PrintJob
{
    Storage::disk(config('printing.disk'))->put('print-jobs/mgmt.pdf', '%PDF-1.4 fake');
    $job = PrintJob::create([
        'printer_id' => $printer->id, 'kiosk_location_id' => $printer->kiosk_location_id, 'media' => $printer->media,
        'title' => 'A document', 'pdf_path' => 'print-jobs/mgmt.pdf', 'requested_by' => $requestedBy,
    ]);
    if ($overrides) {
        $job->forceFill($overrides)->save();
    }

    return $job->fresh();
}

beforeEach(fn () => Storage::fake(config('printing.disk')));

test('a manager sees everyone\'s active jobs, a printer user only their own', function () {
    $printer = mgmtPrinter();
    $mine = userWithPermissions('print-documents');
    $other = userWithPermissions('print-documents');
    mgmtJob($printer, [], $mine->id);
    mgmtJob($printer, [], $other->id);

    $own = $this->actingAs($mine)->getJson('/json/print-jobs')->assertOk();
    expect($own->json('jobs'))->toHaveCount(1)->and($own->json('can_manage'))->toBeFalse();

    $all = $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))->getJson('/json/print-jobs')->assertOk();
    expect($all->json('jobs'))->toHaveCount(2)->and($all->json('can_manage'))->toBeTrue();
});

test('the list splits active from history and reports per-printer pending counts', function () {
    $printer = mgmtPrinter();
    $manager = userWithPermissions('print-documents', 'manage-print-queue');
    mgmtJob($printer);
    mgmtJob($printer, ['status' => 'failed', 'error_message' => 'Out of paper']);
    mgmtJob($printer, ['status' => 'printed']);
    mgmtJob($printer, ['status' => 'cancelled']);

    $active = $this->actingAs($manager)->getJson('/json/print-jobs')->json();
    expect(collect($active['jobs'])->pluck('status')->sort()->values()->all())->toBe(['failed', 'queued'])
        ->and($active['printers'][0])->toMatchArray(['id' => $printer->id, 'pending' => 1, 'ready' => true]);

    $history = $this->actingAs($manager)->getJson('/json/print-jobs?view=history')->json('jobs');
    expect(collect($history)->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'printed']);
});

test('opening the queue settles a claim whose print server went silent', function () {
    $printer = mgmtPrinter();
    $job = mgmtJob($printer, [
        'status' => 'claimed', 'claimed_by_device_id' => $printer->managed_device_id,
        'claim_expires_at' => now()->subMinute(), 'attempts' => 1,
    ]);

    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))->getJson('/json/print-jobs')->assertOk();

    expect($job->fresh()->status)->toBe('queued');
});

test('job history is visible to its requester and managers, not to other users', function () {
    $printer = mgmtPrinter();
    $owner = userWithPermissions('print-documents');
    $job = mgmtJob($printer, [], $owner->id);
    $job->transitionTo('cancelled', $owner->id, null, [], 'Cancelled');

    $this->actingAs($owner)->getJson("/json/print-jobs/{$job->id}")->assertOk()->assertJsonPath('history.0.to', 'cancelled');
    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))->getJson("/json/print-jobs/{$job->id}")->assertOk();
    $this->actingAs(userWithPermissions('print-documents'))->getJson("/json/print-jobs/{$job->id}")->assertNotFound();
});

test('cancel works on waiting jobs, is refused on finished ones, and needs manage-print-queue', function () {
    $printer = mgmtPrinter();
    $manager = userWithPermissions('print-documents', 'manage-print-queue');
    $queued = mgmtJob($printer);
    $printed = mgmtJob($printer, ['status' => 'printed']);

    $this->actingAs(userWithPermissions('print-documents'))->postJson("/json/print-jobs/{$queued->id}/cancel")->assertForbidden();
    $this->actingAs($manager)->postJson("/json/print-jobs/{$queued->id}/cancel")->assertOk()->assertJsonPath('record.status', 'cancelled');
    $this->actingAs($manager)->postJson("/json/print-jobs/{$printed->id}/cancel")->assertStatus(409);

    expect($queued->fresh()->statusLogs()->latest('id')->first()->changed_by_person_id)->toBe($manager->id);
});

test('retry requeues a failed job with a clean slate, but only a failed one with its file', function () {
    $printer = mgmtPrinter();
    $manager = userWithPermissions('print-documents', 'manage-print-queue');
    $failed = mgmtJob($printer, ['status' => 'failed', 'error_message' => 'Jam', 'attempts' => 3]);
    $queued = mgmtJob($printer);
    $purged = mgmtJob($printer, ['status' => 'failed', 'pdf_path' => null]);

    $this->actingAs($manager)->postJson("/json/print-jobs/{$failed->id}/retry")->assertOk();
    $this->actingAs($manager)->postJson("/json/print-jobs/{$queued->id}/retry")->assertStatus(409);
    $this->actingAs($manager)->postJson("/json/print-jobs/{$purged->id}/retry")->assertStatus(409);

    expect($failed->fresh())->status->toBe('queued')->error_message->toBeNull()->attempts->toBe(0);
});

test('divert moves a waiting job to another printer of the same media', function () {
    $a = mgmtPrinter('A');
    $b = mgmtPrinter('B');
    $label = mgmtPrinter('L', 'label_large');
    $manager = userWithPermissions('print-documents', 'manage-print-queue');
    $job = mgmtJob($a);

    $this->actingAs($manager)->postJson("/json/print-jobs/{$job->id}/divert", ['printer_id' => $label->id])->assertUnprocessable();
    $this->actingAs($manager)->postJson("/json/print-jobs/{$job->id}/divert", ['printer_id' => $b->id])->assertOk()
        ->assertJsonPath('record.printer_name', 'B');
    expect($job->fresh()->printer_id)->toBe($b->id);

    $job->fresh()->transitionTo('claimed', null, $b->managed_device_id);
    $this->actingAs($manager)->postJson("/json/print-jobs/{$job->id}/divert", ['printer_id' => $a->id])->assertUnprocessable();
});

test('divert refuses an inactive printer', function () {
    $a = mgmtPrinter('A');
    $off = mgmtPrinter('Off', 'report', ['active' => false]);
    $job = mgmtJob($a);

    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))
        ->postJson("/json/print-jobs/{$job->id}/divert", ['printer_id' => $off->id])->assertUnprocessable();
});

test('clear cancels waiting and failed jobs on one printer only', function () {
    $a = mgmtPrinter('A');
    $b = mgmtPrinter('B');
    mgmtJob($a);
    mgmtJob($a, ['status' => 'failed']);
    $printed = mgmtJob($a, ['status' => 'printed']);
    $otherPrinterJob = mgmtJob($b);

    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))
        ->postJson('/json/print-jobs/clear', ['printer_id' => $a->id])->assertOk()->assertJsonPath('cleared', 2);

    expect($printed->fresh()->status)->toBe('printed')
        ->and($otherPrinterJob->fresh()->status)->toBe('queued')
        ->and(PrintJob::where('printer_id', $a->id)->where('status', 'cancelled')->count())->toBe(2);
});

test('the queue screen needs print-documents', function () {
    $this->actingAs(userWithPermissions('general-access'))->get('/print-queue')->assertForbidden();
    $this->actingAs(userWithPermissions('general-access'))->getJson('/json/print-jobs')->assertForbidden();
    $this->actingAs(userWithPermissions('print-documents'))->get('/print-queue')->assertOk();
});

test('an admin can type and enable a reported printer', function () {
    $printer = mgmtPrinter('Zebra', 'label_large', ['active' => false, 'media' => null, 'kiosk_location_id' => null]);
    $site = KioskLocation::first();
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->putJson("/json/printers/{$printer->id}", [
        'name' => 'Front Gate', 'media' => 'label_large', 'kiosk_location_id' => $site->id, 'active' => true, 'sort_order' => 2,
    ])->assertOk();

    expect($printer->fresh())->name->toBe('Front Gate')->media->toBe('label_large')->active->toBeTrue()->sort_order->toBe(2)
        ->and(Printer::usable()->count())->toBe(1);
});

test('a printer cannot be switched on without media and a site, or given unknown media', function () {
    $printer = mgmtPrinter('Zebra', 'label_large', ['active' => false, 'media' => null, 'kiosk_location_id' => null]);
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->putJson("/json/printers/{$printer->id}", ['name' => 'X', 'active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('active');
    $this->actingAs($admin)->putJson("/json/printers/{$printer->id}", ['name' => 'X', 'media' => 'poster'])
        ->assertUnprocessable()->assertJsonValidationErrors('media');
});

test('changing a printer\'s media is blocked while jobs wait on it', function () {
    $printer = mgmtPrinter('Zebra', 'report');
    mgmtJob($printer);
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->putJson("/json/printers/{$printer->id}", [
        'name' => 'Zebra', 'media' => 'label_large', 'kiosk_location_id' => $printer->kiosk_location_id, 'active' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('media');
});

test('printer admin needs manage-devices', function () {
    $printer = mgmtPrinter();

    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))
        ->putJson("/json/printers/{$printer->id}", ['name' => 'X'])->assertForbidden();
    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))
        ->deleteJson("/json/printers/{$printer->id}")->assertForbidden();
});

test('a printer with waiting jobs cannot be removed; an idle one can', function () {
    $busy = mgmtPrinter('Busy');
    mgmtJob($busy);
    $idle = mgmtPrinter('Idle');
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->deleteJson("/json/printers/{$busy->id}")->assertStatus(409);
    $this->actingAs($admin)->deleteJson("/json/printers/{$idle->id}")->assertOk();

    expect(Printer::find($busy->id))->not->toBeNull()->and(Printer::find($idle->id))->toBeNull();
});

test('the device list carries each device\'s printers with live status', function () {
    $printer = mgmtPrinter('Zebra');

    $records = $this->actingAs(userWithPermissions('manage-devices'))->getJson('/json/managed-devices')->assertOk()->json('records');

    $response = $this->actingAs(userWithPermissions('manage-devices'))->getJson('/json/managed-devices');
    expect(collect($response->json('media'))->pluck('key')->all())->toBe(array_keys(config('printing.media')));

    $device = collect($records)->firstWhere('id', $printer->managed_device_id);
    expect($device['printers'])->toHaveCount(1)
        ->and($device['printers'][0])->toMatchArray(['queue_name' => 'Zebra', 'status' => 'idle'])
        ->and($device['printers'][0])->not->toHaveKey('device');
});

test('old finished jobs lose their file but keep their row, at most hourly', function () {
    Cache::forget('print-jobs:purge');
    $printer = mgmtPrinter();
    $old = mgmtJob($printer, ['status' => 'printed']);
    $recent = mgmtJob($printer, ['status' => 'printed']);
    $stillQueued = mgmtJob($printer);
    PrintJob::whereKey([$old->id, $stillQueued->id])->update(['updated_at' => now()->subDays(config('printing.retain_days') + 1)]);

    expect(app(PrintQueueService::class)->purgeExpiredFiles())->toBe(1);
    expect($old->fresh()->pdf_path)->toBeNull()
        ->and($recent->fresh()->pdf_path)->not->toBeNull()
        ->and($stillQueued->fresh()->pdf_path)->not->toBeNull();

    // Throttled: a second call within the hour does nothing.
    expect(app(PrintQueueService::class)->purgeExpiredFiles())->toBe(0);
});

test('the printer overview shows every printer with status and waiting jobs, in menu order', function () {
    $second = mgmtPrinter('Second', 'report', ['sort_order' => 20]);
    $first = mgmtPrinter('First', 'report', ['sort_order' => 10]);
    mgmtJob($first);
    mgmtJob($first);
    $admin = userWithPermissions('manage-devices');

    $rows = $this->actingAs($admin)->getJson('/json/printer-overview')->assertOk()->json('printers');

    expect(array_column($rows, 'name'))->toBe(['First', 'Second'])
        ->and($rows[0])->toMatchArray(['status' => 'idle', 'waiting_jobs' => 2, 'failed_jobs' => 0])
        ->and($rows[1]['waiting_jobs'])->toBe(0);
});

test('a drag-and-drop order is saved as sort_order and unknown ids are refused', function () {
    $a = mgmtPrinter('A');
    $b = mgmtPrinter('B');
    $c = mgmtPrinter('C');
    $admin = userWithPermissions('manage-devices');

    $this->actingAs($admin)->postJson('/json/printers/reorder', ['ids' => [$c->id, $a->id, $b->id]])->assertOk();

    expect([$c->fresh()->sort_order, $a->fresh()->sort_order, $b->fresh()->sort_order])->toBe([10, 20, 30]);
    $this->actingAs($admin)->postJson('/json/printers/reorder', ['ids' => [$a->id, 999999]])->assertUnprocessable();
    $this->actingAs(userWithPermissions('manage-print-queue'))->getJson('/json/printer-overview')->assertForbidden();
});

test('the printers page is manage-devices only and tells the page whether the queue can be cleared', function () {
    $this->actingAs(userWithPermissions('manage-print-queue'))->get('/setup/printers')->assertForbidden();
    $this->actingAs(userWithPermissions('manage-devices'))->get('/setup/printers')->assertOk()->assertInertia(fn ($page) => $page->where('canClearQueue', false));
    $this->actingAs(userWithPermissions('manage-devices', 'manage-print-queue'))->get('/setup/printers')->assertInertia(fn ($page) => $page->where('canClearQueue', true));
});
