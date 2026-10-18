<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\ManagedDevice;
use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Device-facing side of the print queue: what a print server's heartbeat does
 * to our printer rows, and the claim/expire mechanics for jobs. The app has no
 * scheduler, so stale-claim expiry runs lazily whenever a server polls.
 */
class PrintQueueService
{
    /**
     * Store a print server's latest queue snapshot and make sure every queue
     * it reports has a printers row. New rows start inactive and untyped (no
     * media) — staff type them on /setup/devices before they're offered in a
     * Print-to menu. Queues that vanish from a report keep their row; they
     * just read as unavailable (see Printer::getStatusAttribute()).
     *
     * @param  array<int, array{queue_name: string, state?: string, state_message?: string|null}>  $queues
     * @param  bool  $wasOnline  whether the device was online before this heartbeat (a return from offline counts as a change)
     */
    public function syncReportedPrinters(ManagedDevice $device, array $queues, bool $wasOnline = true): void
    {
        $clean = collect($queues)
            ->map(fn ($q) => [
                'queue_name' => (string) $q['queue_name'],
                'state' => $q['state'] ?? Printer::STATE_IDLE,
                'state_message' => $q['state_message'] ?? null,
            ])
            ->unique('queue_name')
            ->values();

        // A server coming back online, a queue changing state, or a queue
        // appearing/vanishing all change what the Print menu should show.
        $changed = ! $wasOnline || $device->reported_printers !== $clean->all();

        DB::transaction(function () use ($device, $clean) {
            $device->reported_printers = $clean->all();
            $device->printers_reported_at = now();
            $device->save();

            $known = $device->printers()->pluck('queue_name')->all();
            foreach ($clean->pluck('queue_name')->diff($known) as $queueName) {
                // managed_device_id / queue_name aren't mass-assignable on purpose.
                (new Printer(['name' => $queueName, 'kiosk_location_id' => $device->kiosk_location_id, 'active' => false]))
                    ->forceFill(['managed_device_id' => $device->id, 'queue_name' => $queueName])
                    ->save();
            }
        });

        if ($changed) {
            PrintBroadcaster::printersChanged();
        }
    }

    /**
     * Create a queued job from already-rendered PDF bytes. The file is
     * written first and removed again if the database write fails, so a
     * failure never leaves an orphan PDF behind. requested_by comes from the
     * caller (Auth::id()), never from request input.
     */
    public function enqueue(Printer $printer, string $document, string $title, string $pdfBytes, int $copies, ?int $personId): PrintJob
    {
        $disk = Storage::disk(config('printing.disk'));
        $path = trim(config('printing.directory'), '/').'/'.Str::uuid().'.pdf';
        $disk->put($path, $pdfBytes);

        try {
            return DB::transaction(function () use ($printer, $document, $title, $path, $copies, $personId) {
                $job = PrintJob::create([
                    'printer_id' => $printer->id,
                    'kiosk_location_id' => $printer->kiosk_location_id,
                    'media' => $printer->media,
                    'title' => $title,
                    'document' => $document,
                    'pdf_path' => $path,
                    'copies' => $copies,
                    'requested_by' => $personId,
                ]);
                $job->statusLogs()->create([
                    'from_status' => null,
                    'to_status' => PrintJob::STATUS_QUEUED,
                    'changed_by_person_id' => $personId,
                    'notes' => "Sent to {$printer->name}",
                ]);
                PrintBroadcaster::jobChanged($job);

                return $job;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    /**
     * Return claims whose server never reported back to the queue (or fail
     * them once they've used up their attempts). Cheap enough to run on every
     * poll.
     */
    public function reclaimExpiredClaims(): int
    {
        $max = (int) config('printing.max_attempts');
        $count = 0;

        $expired = PrintJob::where('status', PrintJob::STATUS_CLAIMED)
            ->where('claim_expires_at', '<', now())
            ->pluck('id');

        foreach ($expired as $id) {
            DB::transaction(function () use ($id, $max, &$count) {
                $job = PrintJob::whereKey($id)->lockForUpdate()->first();
                // Re-check under the lock: the server may have reported in meanwhile.
                if (! $job || $job->status !== PrintJob::STATUS_CLAIMED || $job->claim_expires_at >= now()) {
                    return;
                }
                $deviceId = $job->claimed_by_device_id;
                if ($job->attempts >= $max) {
                    $job->transitionTo(PrintJob::STATUS_FAILED, null, $deviceId, [
                        'error_message' => 'The print server stopped responding while printing this job.',
                    ], 'Claim expired; attempts exhausted');
                } else {
                    $job->transitionTo(PrintJob::STATUS_QUEUED, null, $deviceId, [
                        'claimed_by_device_id' => null,
                        'claimed_at' => null,
                        'claim_expires_at' => null,
                    ], 'Claim expired; returned to queue');
                }
                $count++;
            });
        }

        return $count;
    }

    /**
     * Delete the stored PDFs of finished jobs past the retention window. The
     * job rows stay as history; only the (large) files go. Throttled to once
     * an hour through the cache, since the app has no scheduler — it piggybacks
     * on print-server polls.
     */
    public function purgeExpiredFiles(): int
    {
        if (! Cache::add('print-jobs:purge', true, now()->addHour())) {
            return 0;
        }
        $count = 0;
        PrintJob::whereNotNull('pdf_path')
            ->whereIn('status', [PrintJob::STATUS_PRINTED, PrintJob::STATUS_FAILED, PrintJob::STATUS_CANCELLED])
            ->where('updated_at', '<', now()->subDays((int) config('printing.retain_days')))
            ->each(function (PrintJob $job) use (&$count) {
                $job->purgeFile();
                $count++;
            });

        return $count;
    }

    /** Queued jobs for the printers a device owns (active printers only), oldest first. */
    public function queuedJobsFor(ManagedDevice $device)
    {
        return PrintJob::with('printer')
            ->where('status', PrintJob::STATUS_QUEUED)
            ->whereHas('printer', fn ($q) => $q->where('managed_device_id', $device->id)->where('active', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * Atomically claim a job for a device. Returns [job, error] where error is
     * null on success, 'not_found' if the job isn't one of this device's, or
     * 'conflict' if it's no longer claimable. Re-claiming a job the same
     * device already holds is idempotent (a retry after a lost response).
     *
     * @return array{0: ?PrintJob, 1: ?string}
     */
    public function claim(ManagedDevice $device, int $jobId): array
    {
        return DB::transaction(function () use ($device, $jobId) {
            $job = PrintJob::with('printer')->whereKey($jobId)->lockForUpdate()->first();
            if (! $job || ! $job->printer || $job->printer->managed_device_id !== $device->id) {
                return [null, 'not_found'];
            }
            if ($job->status === PrintJob::STATUS_CLAIMED && $job->claimed_by_device_id === $device->id) {
                return [$job, null];
            }
            if ($job->status !== PrintJob::STATUS_QUEUED) {
                return [$job, 'conflict'];
            }

            $job->transitionTo(PrintJob::STATUS_CLAIMED, null, $device->id, [
                'claimed_by_device_id' => $device->id,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes((int) config('printing.claim_expires_minutes')),
                'attempts' => $job->attempts + 1,
                'error_message' => null,
            ]);

            return [$job, null];
        });
    }

    /**
     * Record a print server's outcome. Idempotent: repeating the same result
     * for a job already in that state succeeds without a second log row.
     *
     * @return array{0: ?PrintJob, 1: ?string} error is null, 'not_found', or 'conflict'
     */
    public function report(ManagedDevice $device, int $jobId, string $result, ?string $error): array
    {
        return DB::transaction(function () use ($device, $jobId, $result, $error) {
            $job = PrintJob::with('printer')->whereKey($jobId)->lockForUpdate()->first();
            if (! $job || ! $job->printer || $job->printer->managed_device_id !== $device->id) {
                return [null, 'not_found'];
            }
            if ($job->status === $result && $job->claimed_by_device_id === $device->id) {
                return [$job, null];
            }
            if ($job->status !== PrintJob::STATUS_CLAIMED || $job->claimed_by_device_id !== $device->id) {
                return [$job, 'conflict'];
            }

            $updates = $result === PrintJob::STATUS_PRINTED
                ? ['printed_at' => now(), 'error_message' => null]
                : ['error_message' => $error ?: 'The print server reported a failure without details.'];
            $job->transitionTo($result, null, $device->id, $updates, $result === PrintJob::STATUS_FAILED ? $error : null);

            return [$job, null];
        });
    }
}
