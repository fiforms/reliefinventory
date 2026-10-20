<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\FirstRunService;
use App\Services\RestoreService;
use Illuminate\Http\Request;

/**
 * First-run wizard's "Restore from a backup" path (/install/restore/*). No login exists yet, so the gate is the
 * wizard's own: every write requires the instance to still need setup (FirstRunService::needsSetup) and, when
 * RI_SETUP_CODE is configured, the code at the start. After that the unguessable upload id is the capability
 * for the chunk calls, exactly as a firmware upload id is. Only the status read stays open afterwards, because
 * the restore itself is what flips needsSetup to false. The work is RestoreService's detached process.
 */
class RestoreController extends Controller
{
    public function __construct(private RestoreService $restore, private FirstRunService $firstRun) {}

    private function requireFreshInstance(): void
    {
        abort_unless($this->firstRun->needsSetup(), 403, 'This instance has already been set up; a backup can only be restored onto a new one.');
    }

    public function start(Request $request)
    {
        $this->requireFreshInstance();
        $data = $request->validate([
            'filename' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:'.config('backup.max_restore_bytes'),
            'setup_code' => [filled(config('app.setup_code')) ? 'required' : 'nullable', 'string'],
        ]);
        if (filled(config('app.setup_code')) && ! hash_equals((string) config('app.setup_code'), (string) $data['setup_code'])) {
            return response()->json(['message' => 'That setup code is not correct.', 'errors' => ['setup_code' => ['That setup code is not correct.']]], 422);
        }
        if ($this->restore->isRunning()) {
            return response()->json(['message' => 'A restore is already running.'], 409);
        }

        @unlink($this->restore->dir().'/status.json'); // a previous failed attempt's message
        $id = $this->restore->uploads()->create(['filename' => $data['filename'], 'size' => $data['size']]);

        return response()->json(['upload_id' => $id, 'chunk_size' => config('backup.upload_chunk_bytes'), 'received' => 0], 201);
    }

    public function uploadStatus(string $id)
    {
        $this->requireFreshInstance();
        $meta = $this->restore->uploads()->meta($id);

        return response()->json(['received' => $this->restore->uploads()->received($id), 'size' => $meta['size']]);
    }

    public function uploadChunk(Request $request, string $id)
    {
        $this->requireFreshInstance();
        abort_if($this->restore->isRunning(), 409, 'A restore is already running.');
        [$status, $payload] = $this->restore->uploads()->append($id, (int) $request->query('offset', -1), $request->getContent());

        return response()->json($payload, $status);
    }

    public function complete(string $id)
    {
        $this->requireFreshInstance();
        $uploads = $this->restore->uploads();
        $meta = $uploads->meta($id);
        $received = $uploads->received($id);
        if ($received !== $meta['size']) {
            return response()->json(['message' => "Upload is incomplete ({$received} of {$meta['size']} bytes).", 'received' => $received], 409);
        }
        if ($this->restore->isRunning()) {
            return response()->json(['message' => 'A restore is already running.'], 409);
        }
        $this->restore->launch($id);

        return response()->json(['message' => 'Restore started.'], 202);
    }

    public function cancel(string $id)
    {
        $this->requireFreshInstance();
        abort_if($this->restore->isRunning(), 409, 'A restore is running and cannot be cancelled.');
        $this->restore->uploads()->meta($id);
        $this->restore->uploads()->delete($id);

        return response()->json(['message' => 'Upload cancelled.']);
    }

    /** Polled by the wizard, including while the database is being replaced (so: no session, no database). */
    public function status()
    {
        return response()->json($this->restore->status())->header('Cache-Control', 'no-store');
    }
}
