<?php

use App\Models\ManagedDevice;
use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Support\Facades\Storage;

// Pairs a fresh device through the real pairing endpoint (own helper so this
// file runs standalone). Returns [device, token].

function beat(string $token, array $printers, array $extra = [])
{
    return test()->withToken($token)->postJson('/api/devices/heartbeat', array_merge(['print_printers' => $printers], $extra));
}

// A queued job with a real stored PDF on a typed, active printer.
function queuedJobOn(ManagedDevice $device, string $queue = 'Zebra', array $printerOverrides = []): PrintJob
{
    Storage::fake(config('printing.disk'));
    $printer = $device->printers()->where('queue_name', $queue)->first();
    $printer->forceFill(array_merge(['media' => 'label_large', 'active' => true], $printerOverrides))->save();
    Storage::disk(config('printing.disk'))->put('print-jobs/1.pdf', '%PDF-1.4 fake');

    return PrintJob::create([
        'printer_id' => $printer->id, 'kiosk_location_id' => $printer->kiosk_location_id, 'media' => 'label_large',
        'title' => 'Pallet label', 'pdf_path' => 'print-jobs/1.pdf', 'copies' => 2,
    ]);
}

test('heartbeat records platform and queues, creating inactive untyped printers', function () {
    [$device, $token] = pairPrintServer();

    beat($token, [
        ['queue_name' => 'Zebra', 'state' => 'idle'],
        ['queue_name' => 'Laser', 'state' => 'error', 'state_message' => 'Toner low'],
    ], ['print_platform' => 'windows'])->assertOk();

    $device->refresh();
    expect($device->platform)->toBe('windows')
        ->and($device->reported_printers)->toHaveCount(2)
        ->and($device->printers()->count())->toBe(2);

    $zebra = $device->printers()->where('queue_name', 'Zebra')->first();
    expect($zebra->active)->toBeFalse()->and($zebra->media)->toBeNull()
        ->and($zebra->kiosk_location_id)->toBe($device->kiosk_location_id);
});

test('print_platform is validated, and the old un-namespaced key is ignored', function () {
    [$device, $token] = pairPrintServer();

    $this->withToken($token)->postJson('/api/devices/heartbeat', ['print_platform' => 'amiga'])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/devices/heartbeat', ['platform' => 'macos'])->assertOk();
    expect($device->fresh()->platform)->toBeNull();

    $this->withToken($token)->postJson('/api/devices/heartbeat', ['print_platform' => 'linux'])->assertOk();
    expect($device->fresh()->platform)->toBe('linux');
});

test('a device reporting print queues with no Function set becomes a print station', function () {
    [$device, $token] = pairPrintServer();
    expect($device->kiosk_function)->toBeNull();

    $response = beat($token, [['queue_name' => 'Zebra']])->assertOk();

    expect($device->fresh()->kiosk_function)->toBe('print_station')
        ->and($response->json('kiosk_function'))->toBe('print_station');
});

test('an empty queue list, or no list at all, does not set a Function', function () {
    [$device, $token] = pairPrintServer();

    beat($token, [])->assertOk();
    $this->withToken($token)->postJson('/api/devices/heartbeat', [])->assertOk();

    expect($device->fresh()->kiosk_function)->toBeNull();
});

test('an admin-chosen Function is never overridden by reporting print queues', function () {
    [$device, $token] = pairPrintServer();
    $device->forceFill(['kiosk_function' => 'volunteer_checkin'])->save();

    beat($token, [['queue_name' => 'Zebra']])->assertOk();

    expect($device->fresh()->kiosk_function)->toBe('volunteer_checkin');
});

test('a repeat heartbeat does not duplicate printers or clobber staff settings', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']])->assertOk();
    $device->printers()->first()->forceFill(['media' => 'label_large', 'active' => true, 'name' => 'Front Gate'])->save();

    beat($token, [['queue_name' => 'Zebra', 'state' => 'printing']])->assertOk();

    $printers = $device->printers()->get();
    expect($printers)->toHaveCount(1)
        ->and($printers[0]->name)->toBe('Front Gate')
        ->and($printers[0]->media)->toBe('label_large')
        ->and($printers[0]->status)->toBe('printing');
});

test('a vanished queue keeps its row but reads as unavailable', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra'], ['queue_name' => 'Laser']])->assertOk();
    beat($token, [['queue_name' => 'Laser']])->assertOk();

    expect($device->printers()->count())->toBe(2)
        ->and($device->printers()->where('queue_name', 'Zebra')->first()->status)->toBe('unavailable');
});

test('a heartbeat without print_printers leaves the snapshot alone', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']])->assertOk();

    $this->withToken($token)->postJson('/api/devices/heartbeat', [])->assertOk();

    expect($device->fresh()->reported_printers)->toHaveCount(1);
});

test('print endpoints require device auth', function () {
    $this->getJson('/api/devices/print-jobs')->assertUnauthorized();
    $this->withToken('nope')->getJson('/api/devices/print-jobs')->assertUnauthorized();
    $this->postJson('/api/devices/print-jobs/1/claim')->assertUnauthorized();
    $this->getJson('/api/devices/print-jobs/1/payload')->assertUnauthorized();
    $this->postJson('/api/devices/print-jobs/1/result', ['status' => 'printed'])->assertUnauthorized();
});

test('poll lists only this device\'s queued jobs on active printers', function () {
    [$device, $token] = pairPrintServer('ps-1');
    [$other, $otherToken] = pairPrintServer('ps-2');
    beat($token, [['queue_name' => 'Zebra'], ['queue_name' => 'Idle']]);
    beat($otherToken, [['queue_name' => 'Zebra']]);

    $mine = queuedJobOn($device);
    queuedJobOn($other);
    // A job on an inactive printer of mine isn't offered.
    $inactive = $device->printers()->where('queue_name', 'Idle')->first();
    PrintJob::create(['printer_id' => $inactive->id, 'media' => 'label_large', 'title' => 'x']);

    $jobs = $this->withToken($token)->getJson('/api/devices/print-jobs')->assertOk()->json('jobs');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['id'])->toBe($mine->id)
        ->and($jobs[0]['queue_name'])->toBe('Zebra')
        ->and($jobs[0]['copies'])->toBe(2)
        ->and($jobs[0]['width_in'])->toEqual(4.0)
        ->and($jobs[0]['height_in'])->toEqual(6.5);
});

test('claim, download, and report printed', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")
        ->assertOk()->assertJsonPath('queue_name', 'Zebra');
    expect($job->fresh()->status)->toBe('claimed')->and($job->fresh()->attempts)->toBe(1);

    $this->withToken($token)->get("/api/devices/print-jobs/{$job->id}/payload")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'printed'])
        ->assertOk()->assertJsonPath('status', 'printed');

    expect($job->fresh()->printed_at)->not->toBeNull()
        ->and($job->statusLogs()->pluck('to_status')->all())->toBe(['claimed', 'printed']);
});

test('report failed stores a helpful error and is not auto-retried', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", [
        'status' => 'failed', 'error' => 'Printer out of labels',
    ])->assertOk();

    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_message)->toBe('Printer out of labels');
    $this->withToken($token)->getJson('/api/devices/print-jobs')->assertJsonCount(0, 'jobs');
});

test('result validates its status', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'bogus'])
        ->assertUnprocessable();
});

test('another device cannot see, claim, download, or report a job (404)', function () {
    [$device, $token] = pairPrintServer('ps-1');
    [, $otherToken] = pairPrintServer('ps-2');
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    $this->withToken($otherToken)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertNotFound();
    $this->withToken($otherToken)->get("/api/devices/print-jobs/{$job->id}/payload")->assertNotFound();
    $this->withToken($otherToken)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'printed'])->assertNotFound();
    expect($job->fresh()->status)->toBe('claimed');
});

test('payload requires a claim first', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);

    $this->withToken($token)->get("/api/devices/print-jobs/{$job->id}/payload")->assertStatus(409);
});

test('a purged job file reads as gone', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    Storage::disk(config('printing.disk'))->delete('print-jobs/1.pdf');

    $this->withToken($token)->get("/api/devices/print-jobs/{$job->id}/payload")->assertStatus(410);
});

test('double claim: only one wins, the same device re-claiming is idempotent', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    expect($job->fresh()->attempts)->toBe(1)
        ->and($job->statusLogs()->where('to_status', 'claimed')->count())->toBe(1);

    // Once printed it can't be claimed again.
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'printed'])->assertOk();
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertStatus(409);
});

test('re-claiming a held job extends its claim without a new attempt or log row', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    $job->forceFill(['claim_expires_at' => now()->addMinute()])->save();

    $response = $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    expect($job->fresh()->claim_expires_at->isAfter(now()->addMinutes(config('printing.claim_expires_minutes') - 1)))->toBeTrue()
        ->and($response->json('claim_expires_at'))->toBe($job->fresh()->claim_expires_at->toIso8601String())
        ->and($job->fresh()->attempts)->toBe(1)
        ->and($job->statusLogs()->where('to_status', 'claimed')->count())->toBe(1);
});

test('a keep-alive saves a long-running job from being reclaimed and printed twice', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    // The claim is about to lapse; the print server is still printing and checks in.
    $job->forceFill(['claim_expires_at' => now()->addSeconds(5)])->save();
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    // Time passes past the original expiry; a poll must leave it alone.
    $this->travel(config('printing.claim_expires_minutes') - 1)->minutes();
    $this->withToken($token)->getJson('/api/devices/print-jobs')->assertJsonCount(0, 'jobs');
    expect($job->fresh()->status)->toBe('claimed');

    // Whereas without any further keep-alive it does lapse back to the queue.
    $this->travel(2)->minutes();
    $this->withToken($token)->getJson('/api/devices/print-jobs')->assertJsonCount(1, 'jobs');
    expect($job->fresh()->status)->toBe('queued');
});

test('another device cannot keep a job\'s claim alive', function () {
    [$device, $token] = pairPrintServer('ps-1');
    [, $otherToken] = pairPrintServer('ps-2');
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    $expires = $job->fresh()->claim_expires_at;

    $this->withToken($otherToken)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertNotFound();

    expect($job->fresh()->claim_expires_at->equalTo($expires))->toBeTrue();
});

test('repeating a result is idempotent but a contradicting one conflicts', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'printed'])->assertOk();
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'printed'])->assertOk();
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'failed', 'error' => 'x'])->assertStatus(409);

    expect($job->statusLogs()->where('to_status', 'printed')->count())->toBe(1);
});

test('a stale claim returns to the queue on the next poll', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    $job->forceFill(['claim_expires_at' => now()->subMinute()])->save();

    $jobs = $this->withToken($token)->getJson('/api/devices/print-jobs')->json('jobs');

    expect($jobs)->toHaveCount(1)
        ->and($job->fresh()->status)->toBe('queued')
        ->and($job->fresh()->claimed_by_device_id)->toBeNull();
});

test('a stale claim fails once attempts are exhausted', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    $job->forceFill(['claim_expires_at' => now()->subMinute(), 'attempts' => config('printing.max_attempts')])->save();

    $this->withToken($token)->getJson('/api/devices/print-jobs')->assertJsonCount(0, 'jobs');

    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_message)->not->toBeNull();
});

test('an unexpired claim is left alone by polling', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);
    $job = queuedJobOn($device);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    $this->withToken($token)->getJson('/api/devices/print-jobs')->assertJsonCount(0, 'jobs');

    expect($job->fresh()->status)->toBe('claimed');
});

test('printer model relation sanity: printers belong to the reporting device', function () {
    [$device, $token] = pairPrintServer();
    beat($token, [['queue_name' => 'Zebra']]);

    expect(Printer::first()->device->is($device))->toBeTrue();
});
