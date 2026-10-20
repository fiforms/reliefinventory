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
 * Backs the Backups section of /setup/system (permission: manage-backups): start an on-demand backup zip, list,
 * download and delete them. The work itself is BackupService's detached process; this only starts it and reports status.
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
        // A files-only zip is a disaster-recovery tool (`backup:create --scope=storage`); the page offers the two
        // everyday choices only.
        $data = $request->validate(['scope' => 'required|in:database,full']);

        try {
            $backup = $this->backups->start(Auth::id(), $data['scope']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        Log::notice('Backup requested', ['backup' => $backup->id, 'person' => Auth::id(), 'scope' => $backup->scope]);

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

    /**
     * Download a scheduled (tiered) backup of a bare-metal install: /var/backups/.../<tier>/<stamp>.zip, made by
     * scripts/update.sh and readable by the web user for exactly this. Same permission and logging as an on-demand
     * zip. Tier and stamp are matched against fixed patterns, so nothing from the URL ever reaches the filesystem
     * unchecked, and a Docker install (no tiers) has nothing to serve.
     */
    public function downloadTier(string $tier, string $stamp)
    {
        abort_if(config('system.runtime') === 'docker', 404);
        abort_unless(in_array($tier, ['hourly', 'daily', 'monthly', 'yearly'], true) && preg_match('/^\d{8}-\d{6}$/', $stamp) === 1, 404);
        $path = rtrim(config('system.backup_dir'), '/')."/{$tier}/{$stamp}.zip";
        abort_unless(is_file($path) && is_readable($path), 404);
        Log::notice('Scheduled backup downloaded', ['tier' => $tier, 'stamp' => $stamp, 'person' => Auth::id()]);

        return response()->download($path, "relief-backup-{$tier}-{$stamp}.zip", ['Cache-Control' => 'no-store']);
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
