<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\PrintQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * The print queue screen's API. Anyone with print-documents can look at
 * their own jobs; manage-print-queue sees everyone's and can cancel, retry,
 * divert and clear. Mutations are gated per-route on manage-print-queue.
 */
class PrintJobController extends Controller
{
    private const ACTIVE = [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED, PrintJob::STATUS_FAILED];

    private const FINISHED = [PrintJob::STATUS_PRINTED, PrintJob::STATUS_CANCELLED];

    public function index(Request $request, PrintQueueService $queue)
    {
        $data = $request->validate([
            'view' => 'nullable|in:active,history',
            'printer_id' => 'nullable|integer',
        ]);
        $manager = $this->isManager($request);

        // A claim whose print server died would otherwise sit "claimed"
        // forever when nothing is polling — settle it when someone looks.
        $queue->reclaimExpiredClaims();

        $jobs = PrintJob::with(['printer.location', 'requestedBy'])
            ->when(! $manager, fn ($q) => $q->where('requested_by', Auth::id()))
            ->when($data['printer_id'] ?? null, fn ($q, $id) => $q->where('printer_id', $id))
            ->whereIn('status', ($data['view'] ?? 'active') === 'history' ? self::FINISHED : self::ACTIVE)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $printers = Printer::usable()->with(['device', 'location'])->get()
            ->sortBy(fn (Printer $p) => [$p->location?->name, $p->sort_order, $p->name])
            ->values();
        $pending = PrintJob::whereIn('status', [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED])
            ->selectRaw('printer_id, count(*) as n')->groupBy('printer_id')->pluck('n', 'printer_id');

        return response()->json([
            'can_manage' => $manager,
            'jobs' => $jobs->map(fn (PrintJob $job) => $this->present($job)),
            'printers' => $printers->map(fn (Printer $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'site' => $p->location?->name,
                'media' => $p->media,
                'media_label' => $p->media_label,
                'status' => $p->status,
                'status_message' => $p->status_message,
                'ready' => $p->isReady(),
                'pending' => (int) ($pending[$p->id] ?? 0),
            ]),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $job = PrintJob::with(['printer.location', 'requestedBy', 'statusLogs.changedBy'])->findOrFail($id);
        $this->authorizeView($request, $job);

        return response()->json([
            'record' => $this->present($job),
            'history' => $job->statusLogs->map(fn ($log) => [
                'at' => $log->created_at?->toIso8601String(),
                'from' => $log->from_status,
                'to' => $log->to_status,
                'by' => $log->changedBy?->full_name,
                'notes' => $log->notes,
            ]),
        ]);
    }

    public function cancel(int $id)
    {
        $job = PrintJob::findOrFail($id);
        if (! PrintJob::canTransition($job->status, PrintJob::STATUS_CANCELLED)) {
            return response()->json(['message' => "A {$job->status} job can't be cancelled."], 409);
        }
        $job->transitionTo(PrintJob::STATUS_CANCELLED, Auth::id(), null, [], 'Cancelled');

        return response()->json(['record' => $this->present($job->fresh(['printer.location', 'requestedBy']))]);
    }

    public function retry(int $id)
    {
        $job = PrintJob::findOrFail($id);
        if ($job->status !== PrintJob::STATUS_FAILED) {
            return response()->json(['message' => 'Only a failed job can be retried.'], 409);
        }
        if (! $job->pdf_path) {
            return response()->json(['message' => 'This job\'s file has been cleaned up; print the document again.'], 409);
        }
        $job->transitionTo(PrintJob::STATUS_QUEUED, Auth::id(), null, [
            'error_message' => null,
            'attempts' => 0,
            'claimed_by_device_id' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
        ], 'Retried');

        return response()->json(['record' => $this->present($job->fresh(['printer.location', 'requestedBy']))]);
    }

    public function divert(Request $request, int $id)
    {
        $data = $request->validate(['printer_id' => 'required|integer|exists:printers,id']);
        $job = PrintJob::findOrFail($id);
        $printer = Printer::usable()->find($data['printer_id']);
        if (! $printer) {
            return response()->json(['message' => 'That printer is not available for printing.'], 422);
        }
        if ($job->status === PrintJob::STATUS_FAILED && ! $job->pdf_path) {
            return response()->json(['message' => 'This job\'s file has been cleaned up; print the document again.'], 409);
        }

        try {
            $job->divertTo($printer, Auth::id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['record' => $this->present($job->fresh(['printer.location', 'requestedBy']))]);
    }

    /** Cancel everything still waiting (queued or failed) on one printer. */
    public function clear(Request $request)
    {
        $data = $request->validate(['printer_id' => 'required|integer|exists:printers,id']);
        $count = 0;

        PrintJob::where('printer_id', $data['printer_id'])
            ->whereIn('status', [PrintJob::STATUS_QUEUED, PrintJob::STATUS_FAILED])
            ->each(function (PrintJob $job) use (&$count) {
                $job->transitionTo(PrintJob::STATUS_CANCELLED, Auth::id(), null, [], 'Cleared from queue');
                $count++;
            });

        return response()->json(['cleared' => $count]);
    }

    private function isManager(Request $request): bool
    {
        return (bool) $request->user()->hasPermission('manage-print-queue');
    }

    private function authorizeView(Request $request, PrintJob $job): void
    {
        if (! $this->isManager($request) && $job->requested_by !== Auth::id()) {
            abort(404);
        }
    }

    private function present(PrintJob $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'document' => $job->document,
            'media' => $job->media,
            'copies' => $job->copies,
            'status' => $job->status,
            'error_message' => $job->error_message,
            'attempts' => $job->attempts,
            'printer_id' => $job->printer_id,
            'printer_name' => $job->printer?->name,
            'site' => $job->printer?->location?->name,
            'requested_by' => $job->requestedBy?->full_name,
            'has_file' => (bool) $job->pdf_path,
            'created_at' => $job->created_at?->toIso8601String(),
            'claimed_at' => $job->claimed_at?->toIso8601String(),
            'printed_at' => $job->printed_at?->toIso8601String(),
        ];
    }
}
