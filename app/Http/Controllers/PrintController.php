<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Services\Printing\DocumentRegistry;
use App\Services\PrintQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Web-UI half of the print queue: "send this document to that printer."
 * The PDF is rendered here, at submit time, from the same builder the
 * document's download route uses, then stored and queued for a print server
 * to claim (see DevicePrintJobController).
 */
class PrintController extends Controller
{
    public function store(Request $request, DocumentRegistry $registry, PrintQueueService $queue)
    {
        $data = $request->validate([
            'document' => 'required|string',
            'params' => 'nullable|array',
            'printer_id' => 'required|integer|exists:printers,id',
            'copies' => 'nullable|integer|min:1|max:99',
        ]);

        $definition = $registry->find($data['document']);
        if (! $definition) {
            throw ValidationException::withMessages(['document' => 'Unknown document.']);
        }

        // Same bar as downloading the PDF itself — printing never widens access.
        $user = $request->user();
        if ($definition['permission'] && ! $user->hasPermission($definition['permission'])) {
            abort(403, 'You do not have permission to print this document.');
        }

        // validate() returns only the keys the document's rules name.
        $params = Validator::make($data['params'] ?? [], $definition['rules'])->validate();

        $printer = Printer::usable()->find($data['printer_id']);
        if (! $printer) {
            throw ValidationException::withMessages(['printer_id' => 'That printer is not available for printing.']);
        }
        if ($printer->media !== $definition['media']) {
            throw ValidationException::withMessages([
                'printer_id' => 'That printer is loaded with '.($printer->media_label ?? 'a different media type').', which does not fit this document.',
            ]);
        }

        // Render before touching storage/the DB. abort()s inside a builder
        // (404 no pallets, 409 BOL not eligible) surface as their own status.
        $pdf = $registry->render($definition, $params)->generatePdfContent();
        $title = ($definition['title'])($params);

        $job = $queue->enqueue($printer, $data['document'], $title, $pdf, $data['copies'] ?? 1, Auth::id());

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'title' => $job->title,
            'printer' => ['id' => $printer->id, 'name' => $printer->name, 'status' => $printer->status, 'ready' => $printer->isReady()],
            'message' => $printer->isReady()
                ? "Sent to {$printer->name}."
                : "Queued for {$printer->name}, which is {$printer->status}; it will print when the print server is back.",
        ], 201);
    }
}
