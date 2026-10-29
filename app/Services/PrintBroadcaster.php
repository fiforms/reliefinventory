<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Events\PrintersChanged;
use App\Events\PrintJobAvailable;
use App\Events\PrintJobChanged;
use App\Models\ManagedDevice;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place print-queue realtime messages are sent from. Realtime is an
 * optional accelerator (Laravel Reverb): HTTP polling still works with it off
 * or down, so nothing here may ever make a print, a claim, or a heartbeat
 * fail — every send is skipped unless Reverb is the configured broadcaster,
 * waits for the surrounding transaction to commit (a browser that re-fetches
 * on the message must see the new state), and swallows+reports any error.
 */
class PrintBroadcaster
{
    public static function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));
    }

    /** Something about this job changed: update the queue screens and, if it's waiting, wake its print server. */
    public static function jobChanged(PrintJob $job): void
    {
        if (! self::enabled()) {
            return;
        }
        self::afterCommit(function () use ($job) {
            // load(), not loadMissing(): a divert changes printer_id on a model whose
            // old printer relation may already be cached, and the wake must reach the new one.
            $job->load('printer.device');
            event(new PrintJobChanged([
                'job_id' => $job->id,
                'status' => $job->status,
                'title' => $job->title,
                'printer_id' => $job->printer_id,
                'printer_name' => $job->printer?->name,
                'error_message' => $job->error_message,
                // Managers receive everyone's jobs on print-queue; this lets a browser tell its own apart.
                'requested_by' => $job->requested_by,
            ], $job->requested_by));

            if ($job->status === PrintJob::STATUS_QUEUED && $job->printer?->device) {
                event(new PrintJobAvailable($job->printer->device->wakeChannel()));
            }
        });
    }

    /** A printer's reported state (or the set of printers) may have changed. */
    public static function printersChanged(): void
    {
        if (! self::enabled()) {
            return;
        }
        self::afterCommit(fn () => event(new PrintersChanged));
    }

    /**
     * Connection details a print server needs to listen for wake-ups, sent in
     * its heartbeat response — or null when realtime is off, so a device falls
     * back to plain polling. The channel name is the secret; it is rotated on
     * every (re)pair and dies with a revoke.
     */
    public static function connectionFor(ManagedDevice $device): ?array
    {
        if (! self::enabled() || $device->kiosk_function !== 'print_station') {
            return null;
        }
        // Devices may reach Reverb at a different (public) address than the one
        // the app publishes to — see config/printing.php.
        $options = config('broadcasting.connections.reverb.options');
        $public = array_filter(config('printing.realtime'), fn ($v) => filled($v));

        return [
            'driver' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $public['host'] ?? $options['host'],
            'port' => (int) ($public['port'] ?? $options['port']),
            'scheme' => $public['scheme'] ?? $options['scheme'],
            'path' => config('reverb.servers.reverb.path', ''),
            'channel' => $device->wakeChannel(),
            'event' => 'print-job.available',
        ];
    }

    private static function afterCommit(callable $send): void
    {
        DB::afterCommit(function () use ($send) {
            try {
                $send();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
