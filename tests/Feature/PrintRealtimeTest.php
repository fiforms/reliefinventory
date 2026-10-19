<?php

use App\Events\PrintersChanged;
use App\Events\PrintJobAvailable;
use App\Events\PrintJobChanged;
use App\Models\DevicePairingCode;
use App\Models\KioskLocation;
use App\Models\ManagedDevice;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\PrintQueueService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

const RT_EVENTS = [PrintJobChanged::class, PrintersChanged::class, PrintJobAvailable::class];

function realtimeOn(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => 'ri.example.org',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'printing.realtime' => ['host' => null, 'port' => null, 'scheme' => null],
    ]);
}

// Pairs through the real endpoint; returns [device, token].
function rtPair(string $uuid = 'rt-1', ?string $function = 'print_station'): array
{
    $location = KioskLocation::first() ?? KioskLocation::create(['name' => 'Site']);
    $code = DevicePairingCode::create([
        'code' => (string) random_int(100000, 999999), 'kiosk_location_id' => $location->id, 'expires_at' => now()->addMinutes(5),
    ]);
    $response = test()->postJson('/api/devices/pair', ['code' => $code->code, 'device_name' => "Server {$uuid}", 'device_uuid' => $uuid])->assertCreated();
    $device = ManagedDevice::where('device_uuid', $uuid)->first();
    $device->forceFill(['kiosk_function' => $function])->save();

    return [$device, $response->json('token')];
}

function rtBeat(string $token, array $printers = [['queue_name' => 'Q', 'state' => 'idle']])
{
    return test()->withToken($token)->postJson('/api/devices/heartbeat', ['print_printers' => $printers]);
}

// An active, typed printer on a paired print server, plus a queued job on it.
function rtPrinter(ManagedDevice $device, string $queue = 'Q', string $media = 'report'): Printer
{
    $printer = $device->printers()->where('queue_name', $queue)->firstOrFail();
    $printer->forceFill(['media' => $media, 'active' => true, 'name' => $queue])->save();

    return $printer;
}

function rtEnqueue(Printer $printer, ?int $requestedBy = null): PrintJob
{
    return app(PrintQueueService::class)->enqueue($printer, 'inventory-report', 'Inventory Report', '%PDF-1.4 fake', 1, $requestedBy);
}

beforeEach(function () {
    Storage::fake(config('printing.disk'));
    realtimeOn();
});

test('enqueueing broadcasts to the queue and requester channels and wakes the print server', function () {
    Event::fake(RT_EVENTS);
    [$device, $token] = rtPair();
    rtBeat($token);
    $user = userWithPermissions('print-documents');

    $job = rtEnqueue(rtPrinter($device), $user->id);

    Event::assertDispatched(PrintJobChanged::class, function ($e) use ($job, $user) {
        $channels = collect($e->broadcastOn())->map->name->all();

        return $e->job['job_id'] === $job->id && $e->job['status'] === 'queued' && $e->job['requested_by'] === $user->id
            && $channels === ['private-print-queue', 'private-print-user.'.$user->id];
    });
    Event::assertDispatched(PrintJobAvailable::class, fn ($e) => $e->channel === $device->fresh()->wake_channel);
});

test('claim and result changes broadcast without waking the server, and a requeue wakes it again', function () {
    Event::fake(RT_EVENTS);
    [$device, $token] = rtPair();
    rtBeat($token);
    $job = rtEnqueue(rtPrinter($device), null);
    Event::assertDispatchedTimes(PrintJobAvailable::class, 1);

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();
    Event::assertDispatched(PrintJobChanged::class, fn ($e) => $e->job['status'] === 'claimed');

    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/result", ['status' => 'failed', 'error' => 'Out of labels'])->assertOk();
    Event::assertDispatched(PrintJobChanged::class, fn ($e) => $e->job['status'] === 'failed' && $e->job['error_message'] === 'Out of labels');
    Event::assertDispatchedTimes(PrintJobAvailable::class, 1);

    $this->actingAs(userWithPermissions('print-documents', 'manage-print-queue'))->postJson("/json/print-jobs/{$job->id}/retry")->assertOk();
    Event::assertDispatchedTimes(PrintJobAvailable::class, 2);
});

test('with no requester only the queue channel is used', function () {
    Event::fake(RT_EVENTS);
    [$device, $token] = rtPair();
    rtBeat($token);

    rtEnqueue(rtPrinter($device), null);

    Event::assertDispatched(PrintJobChanged::class, fn ($e) => collect($e->broadcastOn())->map->name->all() === ['private-print-queue']);
});

test('cancel and divert broadcast; a divert wakes the new printer\'s server', function () {
    Event::fake(RT_EVENTS);
    [$a, $tokenA] = rtPair('rt-a');
    [$b, $tokenB] = rtPair('rt-b');
    rtBeat($tokenA);
    rtBeat($tokenB);
    $printerA = rtPrinter($a);
    $printerB = rtPrinter($b);
    $manager = userWithPermissions('print-documents', 'manage-print-queue');
    $job = rtEnqueue($printerA);

    $this->actingAs($manager)->postJson("/json/print-jobs/{$job->id}/divert", ['printer_id' => $printerB->id])->assertOk();
    Event::assertDispatched(PrintJobAvailable::class, fn ($e) => $e->channel === $b->fresh()->wake_channel);

    $this->actingAs($manager)->postJson("/json/print-jobs/{$job->id}/cancel")->assertOk();
    Event::assertDispatched(PrintJobChanged::class, fn ($e) => $e->job['status'] === 'cancelled');
});

test('printer status changes broadcast once, not on an identical heartbeat', function () {
    Event::fake(RT_EVENTS);
    [, $token] = rtPair();

    rtBeat($token, [['queue_name' => 'Q', 'state' => 'idle']]);
    Event::assertDispatchedTimes(PrintersChanged::class, 1);

    rtBeat($token, [['queue_name' => 'Q', 'state' => 'idle']]);
    Event::assertDispatchedTimes(PrintersChanged::class, 1);

    rtBeat($token, [['queue_name' => 'Q', 'state' => 'error', 'state_message' => 'Out of labels']]);
    Event::assertDispatchedTimes(PrintersChanged::class, 2);
});

test('a print server returning from offline refreshes printer status even if nothing else changed', function () {
    Event::fake(RT_EVENTS);
    [$device, $token] = rtPair();
    rtBeat($token);
    Event::assertDispatchedTimes(PrintersChanged::class, 1);

    $device->forceFill(['last_seen_at' => now()->subHour()])->save();
    rtBeat($token);

    Event::assertDispatchedTimes(PrintersChanged::class, 2);
});

test('the heartbeat hands a print server its wake channel and connection details', function () {
    [$device, $token] = rtPair();

    $response = rtBeat($token)->assertOk();

    $realtime = $response->json('print_realtime');
    expect($realtime)->toMatchArray([
        'driver' => 'reverb', 'key' => 'test-key', 'host' => 'ri.example.org', 'port' => 443, 'scheme' => 'https',
        'event' => 'print-job.available',
    ])->and($realtime['channel'])->toStartWith('wake.')->and(strlen($realtime['channel']))->toBeGreaterThan(30);

    // Stable across heartbeats.
    expect(rtBeat($token)->json('print_realtime.channel'))->toBe($realtime['channel']);
});

test('devices can be pointed at a different public address than the app publishes to', function () {
    config(['printing.realtime' => ['host' => 'public.example.org', 'port' => 8443, 'scheme' => 'wss-proxy']]);
    [, $token] = rtPair();

    expect(rtBeat($token)->json('print_realtime'))->toMatchArray([
        'host' => 'public.example.org', 'port' => 8443, 'scheme' => 'wss-proxy', 'key' => 'test-key',
    ]);
});

test('a freshly paired print server with no Function still gets its wake channel on its first heartbeat', function () {
    [$device, $token] = rtPair('rt-fresh', null);
    expect($device->kiosk_function)->toBeNull();

    $response = rtBeat($token)->assertOk();

    expect($response->json('print_realtime.channel'))->toStartWith('wake.')
        ->and($device->fresh()->kiosk_function)->toBe('print_station');
});

test('every print server gets its own wake channel', function () {
    [, $tokenA] = rtPair('rt-a');
    [, $tokenB] = rtPair('rt-b');

    expect(rtBeat($tokenA)->json('print_realtime.channel'))->not->toBe(rtBeat($tokenB)->json('print_realtime.channel'));
});

test('no realtime details when it is off or the device is not a print station', function () {
    [, $printToken] = rtPair('rt-print');
    [, $kioskToken] = rtPair('rt-kiosk', 'volunteer_checkin');

    expect(rtBeat($kioskToken)->json('print_realtime'))->toBeNull();

    config(['broadcasting.default' => 'null']);
    expect(rtBeat($printToken)->json('print_realtime'))->toBeNull();
});

test('re-pairing rotates the wake channel and revoking clears it', function () {
    [$device, $token] = rtPair('rt-1');
    $first = rtBeat($token)->json('print_realtime.channel');

    [, $newToken] = rtPair('rt-1'); // same uuid → re-pair
    $second = rtBeat($newToken)->json('print_realtime.channel');
    expect($second)->not->toBe($first);

    $this->actingAs(userWithPermissions('manage-devices'))->postJson("/json/managed-devices/{$device->id}/revoke")->assertOk();
    expect($device->fresh()->wake_channel)->toBeNull();
});

test('the wake channel is never exposed in the admin device list', function () {
    [, $token] = rtPair();
    rtBeat($token);

    $response = $this->actingAs(userWithPermissions('manage-devices'))->getJson('/json/managed-devices')->assertOk();

    expect($response->getContent())->not->toContain('wake.');
});

test('channel authorization follows print permissions', function () {
    // Channel callbacks register on the default broadcaster at boot (null under
    // phpunit); re-register them on the reverb broadcaster this test switched to.
    require base_path('routes/channels.php');
    $manager = userWithPermissions('print-documents', 'manage-print-queue');
    $printer = userWithPermissions('print-documents');
    $nobody = userWithPermissions('general-access');
    $auth = fn ($user, $channel) => $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

    $auth($manager, 'private-print-queue')->assertOk();
    $auth($printer, 'private-print-queue')->assertForbidden();

    $auth($printer, 'private-print-printers')->assertOk();
    $auth($nobody, 'private-print-printers')->assertForbidden();

    $auth($printer, 'private-print-user.'.$printer->id)->assertOk();
    $auth($printer, 'private-print-user.'.$manager->id)->assertForbidden();
    $auth($nobody, 'private-print-user.'.$nobody->id)->assertForbidden();
});

test('an unreachable Reverb never breaks printing', function () {
    // Real broadcaster, pointed at a closed port: publishing fails fast and is swallowed.
    config([
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 1,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);
    [$device, $token] = rtPair();

    rtBeat($token)->assertOk();
    $job = rtEnqueue(rtPrinter($device), null);
    $this->withToken($token)->postJson("/api/devices/print-jobs/{$job->id}/claim")->assertOk();

    expect($job->fresh()->status)->toBe('claimed');
});

test('nothing is broadcast when Reverb is not the configured broadcaster', function () {
    config(['broadcasting.default' => 'null']);
    Event::fake(RT_EVENTS);
    [$device, $token] = rtPair();
    rtBeat($token);

    rtEnqueue(rtPrinter($device), null);

    Event::assertNothingDispatched();
});
