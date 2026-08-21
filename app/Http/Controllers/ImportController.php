<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Services\Import\ImporterRegistry;
use App\Services\Import\ImportRunResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Upload -> Preview -> Commit for external data imports (currently just
 * Flowtrac). Preview and Commit both go through the same Importer::process()
 * logic (see ImporterRegistry) — a batch is never written to app data
 * until an explicit Commit, and Commit is safe to re-run (idempotent,
 * matched via source_system/source_ref) since Washington runs Flowtrac
 * and reliefinventory in parallel for a while, not a one-time cutover.
 *
 * All files seen so far (Contacts/Products/Donations Received/Current
 * Inventory) are well under 200KB, so both passes run synchronously in the
 * request — no queued job needed yet. If a future export is large enough
 * to time out a request, that's the point to introduce a queued job.
 */
class ImportController extends Controller
{
    public function options()
    {
        return response()->json(['file_types' => ImporterRegistry::options()]);
    }

    public function index()
    {
        $batches = ImportBatch::with('creator')->orderByDesc('id')->get();

        return response()->json(['records' => $batches]);
    }

    public function rows($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $rows = $batch->rows()->orderBy('row_number')->get();

        return response()->json(['records' => $rows]);
    }

    /**
     * Upload a file and run Preview (dry run — no app-data writes) on it
     * immediately, so the response can show proposed counts/mappings.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
            'file_type' => ['required', 'string'],
        ]);

        $importer = ImporterRegistry::forFileType($data['file_type']);
        if (! $importer) {
            return response()->json(['message' => 'Unrecognized file type.'], 422);
        }

        $storedPath = $request->file('file')->store('imports', 'local');

        $batch = ImportBatch::create([
            'source' => $importer->source(),
            'file_type' => $data['file_type'],
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => 'previewed',
            'created_by' => Auth::id(),
            'started_at' => now(),
        ]);

        $result = $importer->process(Storage::disk('local')->path($storedPath), false);
        $this->writeRows($batch, $result);
        $batch->update(['summary' => $result->summary()]);

        return response()->json([
            'record' => $batch->fresh('rows'),
            'decisions' => $result->decisions,
        ], 201);
    }

    /**
     * Re-run the same importer against the already-uploaded file, this
     * time actually writing. Safe to call more than once — the importers
     * match/upsert via source_system/source_ref rather than blind insert.
     */
    public function commit($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $importer = ImporterRegistry::forFileType($batch->file_type, Auth::id());

        if (! $importer) {
            return response()->json(['message' => 'Unrecognized file type.'], 422);
        }

        $batch->update(['status' => 'committing']);

        try {
            $result = DB::transaction(function () use ($importer, $batch) {
                return $importer->process(Storage::disk('local')->path($batch->stored_path), true);
            });

            $batch->rows()->delete();
            $this->writeRows($batch, $result);

            $batch->update([
                'status' => 'completed',
                'summary' => $result->summary(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'record' => $batch->fresh('rows'),
                'decisions' => $result->decisions,
            ]);
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed']);

            return response()->json(['message' => 'Import failed: '.$e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $batch = ImportBatch::findOrFail($id);
        if ($batch->stored_path) {
            Storage::disk('local')->delete($batch->stored_path);
        }
        $batch->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function writeRows(ImportBatch $batch, ImportRunResult $result): void
    {
        foreach ($result->rows as $row) {
            $batch->rows()->create($row);
        }
    }
}
