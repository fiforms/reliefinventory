<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Typing the queues a print server reports (manage-devices): give each a
 * friendly name, a site, and the media it is loaded with, then switch it on.
 * Rows are created by the heartbeat (PrintQueueService::syncReportedPrinters),
 * never here — an admin can only configure or remove them.
 */
class PrinterAdminController extends Controller
{
    /**
     * Every printer with live status and how many jobs are waiting on it, in the order the Print
     * menus use (site, then sort_order) — the data behind /setup/printers.
     */
    public function overview()
    {
        $printers = Printer::with(['device', 'location'])->withCount([
            'jobs as waiting_jobs' => fn ($q) => $q->whereIn('status', [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED]),
            'jobs as failed_jobs' => fn ($q) => $q->where('status', PrintJob::STATUS_FAILED),
        ])->get()
            ->sortBy(fn (Printer $p) => [$p->location?->name ?? '~', $p->sort_order, $p->name ?? $p->queue_name])
            ->values()
            ->map(fn (Printer $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'queue_name' => $p->queue_name,
                'site_id' => $p->kiosk_location_id,
                'site' => $p->location?->name,
                'media_label' => $p->media_label,
                'active' => $p->active,
                'sort_order' => $p->sort_order,
                'status' => $p->status,
                'status_message' => $p->status_message,
                'device_name' => $p->device?->name,
                'waiting_jobs' => $p->waiting_jobs,
                'failed_jobs' => $p->failed_jobs,
            ]);

        return response()->json(['printers' => $printers]);
    }

    /** Saves a drag-and-drop order: `ids` in their new order become sort_order 10, 20, 30… */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer|exists:printers,id|distinct']);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $index => $id) {
                Printer::whereKey($id)->update(['sort_order' => min(9999, ($index + 1) * 10)]);
            }
        });

        return response()->json(['message' => 'Order saved.']);
    }

    public function update(Request $request, Printer $printer)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'kiosk_location_id' => 'nullable|exists:kiosk_locations,id',
            'media' => ['nullable', Rule::in(array_keys(config('printing.media')))],
            'active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);
        $data['sort_order'] ??= 0;

        // A printer offered in menus must be fully described, or a job could
        // be sent somewhere it can't be matched or routed.
        if (($data['active'] ?? false) && (empty($data['media']) || empty($data['kiosk_location_id']))) {
            throw ValidationException::withMessages([
                'active' => 'Choose a site and a paper type before switching a printer on.',
            ]);
        }
        // Changing media under waiting jobs would strand them on the wrong paper.
        if ($printer->media && ($data['media'] ?? null) !== $printer->media
            && $printer->jobs()->whereIn('status', [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED])->exists()) {
            throw ValidationException::withMessages([
                'media' => 'This printer has jobs waiting. Clear or divert them before changing its paper type.',
            ]);
        }

        $printer->fill($data)->save();

        return response()->json(['record' => $printer->fresh()]);
    }

    public function destroy(Printer $printer)
    {
        if ($printer->jobs()->whereIn('status', [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED])->exists()) {
            return response()->json(['message' => 'This printer has jobs waiting. Clear or divert them first.'], 409);
        }
        $printer->delete();

        return response()->json(['message' => 'Printer removed. It will reappear if its print server still reports it.']);
    }
}
