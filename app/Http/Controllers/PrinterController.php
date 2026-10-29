<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Printer;
use Illuminate\Http\Request;

/**
 * Printers offered in a "Print to…" menu. Only active, typed, sited printers
 * appear (Printer::usable()), optionally narrowed to one media type so a
 * label printer is never offered for a letter-size report. Offline/errored
 * printers are included, flagged — a job sent to one just waits in the queue.
 */
class PrinterController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'media' => 'nullable|string|in:'.implode(',', array_keys(config('printing.media'))),
        ]);

        $printers = Printer::usable()
            ->with(['device', 'location'])
            ->when($data['media'] ?? null, fn ($q, $media) => $q->where('media', $media))
            ->get()
            ->sortBy(fn (Printer $p) => [$p->location?->name, $p->sort_order, $p->name])
            ->values()
            ->map(fn (Printer $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'media' => $p->media,
                'media_label' => $p->media_label,
                'site_id' => $p->kiosk_location_id,
                'site' => $p->location?->name,
                'status' => $p->status,
                'status_message' => $p->status_message,
                'ready' => $p->isReady(),
            ]);

        return response()->json(['printers' => $printers]);
    }
}
