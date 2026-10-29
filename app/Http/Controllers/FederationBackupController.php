<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\BackupService;
use App\Services\Federation\TrustService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Backups for a trusted operator tool (the RI Launcher): the same zips the admin page makes, behind the
 * `operator.full_read` grant instead of a login. The peer asks for a fresh backup, watches it, then downloads it
 * (resumable — BinaryFileResponse honours Range) and checks the whole-file SHA-256 from `show`. The server's own
 * retention (`backup.keep_days`) still deletes the zip later, so the peer keeps its own copy.
 */
class FederationBackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index()
    {
        $this->backups->reapInterrupted();

        return response()->json([
            'backups' => Backup::orderByDesc('id')->limit(100)->get()->map(fn (Backup $b) => $this->present($b))->values(),
            'keep_days' => (int) config('backup.keep_days'),
        ]);
    }

    public function store(Request $request)
    {
        // Same two everyday choices as the admin page; a files-only zip is a disaster-recovery tool.
        $data = $request->validate(['scope' => 'required|in:database,full']);
        $trust = $request->attributes->get('trust');

        try {
            $backup = $this->backups->start(null, $data['scope']);
        } catch (RuntimeException $e) {
            // One at a time. Say which one is running so the caller can wait for it instead of guessing.
            $active = Backup::whereIn('status', Backup::ACTIVE)->orderBy('id')->first();

            return response()->json(['message' => $e->getMessage(), 'backup' => $active ? $this->present($active) : null], 409);
        }
        TrustService::audit($trust, 'backup_requested', null, ['backup' => $backup->id, 'scope' => $backup->scope]);
        Log::notice('Backup requested by a trusted peer', ['backup' => $backup->id, 'trust' => $trust->id, 'scope' => $backup->scope]);

        return response()->json(['backup' => $this->present($backup)], 202);
    }

    public function show(int $id)
    {
        $this->backups->reapInterrupted();
        $backup = Backup::findOrFail($id);

        return response()->json(['backup' => $this->present($backup, withHash: true)]);
    }

    public function download(Request $request, int $id)
    {
        $backup = Backup::findOrFail($id);
        abort_unless($backup->status === 'complete' && is_file($backup->path()), 404);

        // A resumed transfer is the same download; only its start is worth an audit row.
        $range = (string) $request->header('Range');
        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            TrustService::audit($request->attributes->get('trust'), 'backup_downloaded', null, ['backup' => $backup->id]);
            Log::notice('Backup downloaded by a trusted peer', ['backup' => $backup->id, 'trust' => $request->attributes->get('trust')->id]);
        }

        return response()->download($backup->path(), $backup->filename, ['Cache-Control' => 'no-store']);
    }

    private function present(Backup $b, bool $withHash = false): array
    {
        $ready = $b->status === 'complete' && is_file($b->path());

        return [
            'id' => $b->id,
            'scope' => $b->scope,
            'status' => $ready || $b->status !== 'complete' ? $b->status : 'missing', // the zip was deleted from disk
            'progress' => $b->progress,
            'filename' => $b->filename,
            'size_bytes' => $b->size_bytes,
            'file_count' => $b->file_count,
            'created_at' => $b->created_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'expires_at' => $b->expires_at?->toIso8601String(),
            'error' => $b->status === 'failed' ? $b->error : null,
            // Hashing a multi-GB zip takes seconds, so only `show` does, and only once per file.
            'sha256' => $withHash && $ready ? $this->sha256($b) : null,
        ];
    }

    private function sha256(Backup $b): string
    {
        $path = $b->path();

        return Cache::rememberForever('backup-sha256:'.$b->id.':'.filesize($path).':'.filemtime($path), fn () => hash_file('sha256', $path));
    }
}
