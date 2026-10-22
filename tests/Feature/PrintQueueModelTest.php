<?php

use App\Models\Printer;
use App\Models\PrintJob;

function makeJob(?Printer $printer, string $media = 'label_large'): PrintJob
{
    return PrintJob::create([
        'printer_id' => $printer?->id, 'kiosk_location_id' => $printer?->kiosk_location_id,
        'media' => $media, 'title' => 'Test', 'copies' => 1,
    ]);
}

test('one print server owns several printers with different media', function () {
    $device = printServer([
        ['queue_name' => 'Zebra', 'state' => 'idle'],
        ['queue_name' => 'Brother', 'state' => 'idle'],
    ]);
    makePrinter($device, 'Zebra', 'label_large');
    makePrinter($device, 'Brother', 'label_address');

    expect($device->printers()->pluck('media')->all())->toEqualCanonicalizing(['label_large', 'label_address']);
});

test('printer status reflects the heartbeat snapshot, and server offline', function () {
    $device = printServer([
        ['queue_name' => 'Zebra', 'state' => 'error', 'state_message' => 'Out of labels'],
        ['queue_name' => 'Laser', 'state' => 'idle'],
    ]);
    $zebra = makePrinter($device, 'Zebra');
    $laser = makePrinter($device, 'Laser', 'report');
    $gone = makePrinter($device, 'Missing');

    expect($zebra->fresh()->status)->toBe('error')
        ->and($zebra->fresh()->status_message)->toBe('Out of labels')
        ->and($zebra->fresh()->isReady())->toBeFalse()
        ->and($laser->fresh()->isReady())->toBeTrue()
        ->and($gone->fresh()->status)->toBe('unavailable');

    $device->forceFill(['last_seen_at' => now()->subHour()])->save();
    expect($laser->fresh()->status)->toBe('unavailable');
});

test('usable scope needs active, typed, sited printers', function () {
    $device = printServer();
    makePrinter($device, 'A');
    makePrinter($device, 'B', 'label_large', ['active' => false]);
    makePrinter($device, 'C', 'label_large', ['kiosk_location_id' => null]);
    $untyped = makePrinter($device, 'D');
    $untyped->media = null;
    $untyped->save();

    expect(Printer::usable()->pluck('queue_name')->all())->toBe(['A']);
});

test('job transitions are legal-move checked and logged', function () {
    $printer = makePrinter(printServer(), 'Zebra');
    $job = makeJob($printer);

    expect($job->status)->toBe('queued');
    $job->refresh();
    $job->transitionTo('claimed', null, $printer->managed_device_id);
    $job->transitionTo('failed', null, $printer->managed_device_id, ['error_message' => 'Paper jam']);
    $job->transitionTo('queued');

    expect(fn () => $job->transitionTo('printed'))->toThrow(InvalidArgumentException::class);
    expect($job->statusLogs()->pluck('to_status')->all())->toBe(['claimed', 'failed', 'queued']);
});

test('divert moves a queued job to a same-media printer only', function () {
    $device = printServer();
    $a = makePrinter($device, 'A');
    $b = makePrinter($device, 'B');
    $report = makePrinter($device, 'R', 'report');
    $job = makeJob($a);
    $job->refresh();

    expect(fn () => $job->divertTo($report, null))->toThrow(InvalidArgumentException::class);

    $job->divertTo($b, null);
    expect($job->fresh()->printer_id)->toBe($b->id)->and($job->fresh()->status)->toBe('queued');

    $job->transitionTo('claimed', null, $device->id);
    expect(fn () => $job->divertTo($a, null))->toThrow(InvalidArgumentException::class);
});

test('diverting a failed job requeues it with a clean slate', function () {
    $device = printServer();
    $a = makePrinter($device, 'A');
    $b = makePrinter($device, 'B');
    $job = makeJob($a);
    $job->refresh();
    $job->transitionTo('claimed', null, $device->id);
    $job->transitionTo('failed', null, $device->id, ['error_message' => 'Offline', 'attempts' => 3]);

    $job->divertTo($b, null);
    $job->refresh();

    expect($job->status)->toBe('queued')
        ->and($job->printer_id)->toBe($b->id)
        ->and($job->error_message)->toBeNull()
        ->and($job->attempts)->toBe(0);
});
