<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Http\Request;
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
