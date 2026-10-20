<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Backs /setup/backups (permission: manage-backups): start an on-demand backup zip, list, download and
 * delete them. The work itself is BackupService's detached process; this only starts it and reports status.
 * The application-key panel is a separate permission (view-app-key) and a password-confirmed POST.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index()
    {
        $this->backups->reapInterrupted();
        $this->backups->prune();

        return response()->json([
            'records' => Backup::orderByDesc('id')->limit(100)->get(),
            'keep_days' => (int) config('backup.keep_days'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['include_uploads' => 'boolean']);

        try {
            $backup = $this->backups->start(Auth::id(), (bool) ($data['include_uploads'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        Log::notice('Backup requested', ['backup' => $backup->id, 'person' => Auth::id(), 'uploads' => $backup->include_uploads]);

        return response()->json(['message' => 'Backup started.', 'record' => $backup], 202);
    }

    public function download(int $id)
    {
        $backup = Backup::findOrFail($id);
        abort_unless($backup->status === 'complete' && is_file($backup->path()), 404);
        Log::notice('Backup downloaded', ['backup' => $backup->id, 'person' => Auth::id()]);

        // BinaryFileResponse streams from disk and honours Range requests, so a multi-GB download can resume.
        return response()->download($backup->path(), $backup->filename, ['Cache-Control' => 'no-store']);
    }

    public function destroy(int $id)
    {
        $backup = Backup::findOrFail($id);
        if ($backup->isActive()) {
            return response()->json(['message' => 'That backup is still running.'], 409);
        }
        $this->backups->delete($backup);
        Log::notice('Backup deleted', ['backup' => $id, 'person' => Auth::id()]);

        return response()->json(['message' => 'Backup deleted.']);
    }

    /**
     * Reveal APP_KEY. A POST (never a GET, so it can't be linked, prefetched or land in a URL log) that
     * re-asks for the person's own password, is throttled at the route, never cached, and is logged.
     */
    public function appKey(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        $user = Auth::user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            Log::warning('App key reveal refused: wrong or missing password', ['person' => $user->id]);

            return response()->json([
                'message' => 'That password is not correct.',
                'errors' => ['password' => [$user->password ? 'That password is not correct.' : 'Your account has no password set, so the key cannot be shown.']],
            ], 422);
        }
        Log::warning('App key revealed', ['person' => $user->id]);

        return response()->json(['key' => config('app.key')])->header('Cache-Control', 'no-store, private');
    }
}
