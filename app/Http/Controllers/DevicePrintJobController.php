<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\PrintJob;
use App\Services\PrintQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Print-server half of the print queue (rstation/PRINT_SERVER.md): a paired
 * device polls for work, claims a job, downloads its PDF, and reports the
 * outcome. Everything is scoped to the authenticated device's own printers —
 * another device's job id reads as 404, never 403, so ids don't leak.
 */
class DevicePrintJobController extends Controller
{
    public function __construct(private PrintQueueService $queue) {}

    public function index(Request $request)
    {
        $device = $request->attributes->get('managed_device');
        $this->queue->reclaimExpiredClaims();

        return response()->json([
            'jobs' => $this->queue->queuedJobsFor($device)->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'queue_name' => $job->printer->queue_name,
                'title' => $job->title,
                'copies' => $job->copies,
                'media' => $job->media,
                'width_in' => config("printing.media.{$job->media}.width"),
                'height_in' => config("printing.media.{$job->media}.height"),
                'created_at' => $job->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function claim(Request $request, int $id)
    {
        [$job, $error] = $this->queue->claim($request->attributes->get('managed_device'), $id);

        return match ($error) {
            'not_found' => response()->json(['message' => 'Not found.'], 404),
            'conflict' => response()->json(['message' => "Job is {$job->status} and can't be claimed."], 409),
            default => response()->json([
                'id' => $job->id,
                'queue_name' => $job->printer->queue_name,
                'copies' => $job->copies,
                'claim_expires_at' => $job->claim_expires_at->toIso8601String(),
            ]),
        };
    }

    public function payload(Request $request, int $id)
    {
        $device = $request->attributes->get('managed_device');
        $job = PrintJob::with('printer')->find($id);

        if (! $job || ! $job->printer || $job->printer->managed_device_id !== $device->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        if ($job->status !== PrintJob::STATUS_CLAIMED || $job->claimed_by_device_id !== $device->id) {
            return response()->json(['message' => 'Claim this job before downloading it.'], 409);
        }
        $disk = Storage::disk(config('printing.disk'));
        if (! $job->pdf_path || ! $disk->exists($job->pdf_path)) {
            return response()->json(['message' => 'The job file is no longer available.'], 410);
        }

        return $disk->response($job->pdf_path, "print-job-{$job->id}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function result(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:printed,failed',
            'error' => 'nullable|string|max:2000',
        ]);

        [$job, $error] = $this->queue->report(
            $request->attributes->get('managed_device'), $id, $data['status'], $data['error'] ?? null
        );

        return match ($error) {
            'not_found' => response()->json(['message' => 'Not found.'], 404),
            'conflict' => response()->json(['message' => "Job is {$job->status}; result not accepted."], 409),
            default => response()->json(['id' => $job->id, 'status' => $job->status]),
        };
    }
}
