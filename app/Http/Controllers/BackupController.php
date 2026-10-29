<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Control\InstanceAuditLog;
use App\Services\BackupService;
use App\Services\RestoreService;
use App\Services\Warehouses\WarehouseContext;
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
    public function __construct(private BackupService $backups, private RestoreService $restore) {}

    public function index()
    {
        $this->backups->reapInterrupted();
        $this->backups->prune();

        return response()->json([
            'records' => Backup::orderByDesc('id')->limit(100)->get(),
            'keep_days' => (int) config('backup.keep_days'),
            'schedule' => $this->schedulePayload(),
        ]);
    }

    /** The warehouse's snapshot schedule, plus what the page needs to explain it (the size of the latest zip for a space estimate). */
    private function schedulePayload(): array
    {
        return BackupSchedule::current()->toArray() + [
            'scheduler_enabled' => (bool) config('backup.container.enabled'),
            'last_size_bytes' => Backup::where('status', 'complete')->orderByDesc('id')->value('size_bytes'),
        ];
    }

    public function schedule()
    {
        return response()->json($this->schedulePayload());
    }

    public function updateSchedule(Request $request)
    {
        $data = $request->validate([
            'hourly_enabled' => 'required|boolean', 'hourly_keep' => 'required|integer|min:1|max:168',
            'daily_enabled' => 'required|boolean', 'daily_hour' => 'required|integer|min:0|max:23',
            'daily_keep' => 'required|integer|min:1|max:90', 'daily_with_files' => 'required|boolean',
            'weekly_enabled' => 'required|boolean', 'weekly_dow' => 'required|integer|min:0|max:6',
            'weekly_keep' => 'required|integer|min:1|max:52', 'weekly_with_files' => 'required|boolean',
            'monthly_enabled' => 'required|boolean', 'monthly_keep' => 'required|integer|min:1|max:36', 'monthly_with_files' => 'required|boolean',
        ]);
        BackupSchedule::current()->update($data);
        Log::notice('Backup schedule changed', ['person' => Auth::id()] + $data);
        InstanceAuditLog::record('warehouse.backup_schedule_changed', app(WarehouseContext::class)->current(), $data, Auth::user()->email);

        return response()->json($this->schedulePayload());
    }

    /**
     * Roll this warehouse back to one of its own snapshots (RestoreService::runSnapshot). Everything entered since the snapshot is lost, so it
     * takes the same two things as a restore from the Restore page: the administrator's permission and password. The restore replaces the
     * database and its sessions, so who is doing it travels in a file (stageOwnRestore) and the person stays Administrator afterwards.
     */
    public function restoreSnapshot(Request $request, int $id)
    {
        $user = Auth::user();
        $warehouse = app(WarehouseContext::class)->current();
        abort_unless($user->hasPermission('admin-system'), 403, 'Missing required permission: admin-system');
        abort_unless($warehouse->isActive() && ! $warehouse->is_training, 403, 'This warehouse cannot be rolled back from here.');
        $data = $request->validate(['password' => 'required|string']);

        if (! $user->password || ! Hash::check($data['password'], $user->password) || ! $user->account) {
            return response()->json(['message' => 'That password is not correct.', 'errors' => ['password' => ['That password is not correct.']]], 422);
        }
        $backup = Backup::findOrFail($id);
        abort_unless($backup->status === 'complete' && $backup->filename && RestoreService::isSnapshotName($backup->filename) && is_file($backup->path()), 404);
        if (! BackupService::hasDatabase($backup->scope)) {
            return response()->json(['message' => 'That backup holds only files, so there is nothing to roll the data back to.'], 422);
        }
        if ($this->restore->isRunning() || Backup::whereIn('status', Backup::ACTIVE)->exists()) {
            return response()->json(['message' => 'A backup or restore is running. Wait for it to finish.'], 409);
        }

        $this->restore->stageOwnRestore([
            'account_id' => $user->account->id, 'first_name' => (string) $user->first_name, 'last_name' => (string) $user->last_name,
            'any_lineage' => false,
        ]);
        InstanceAuditLog::record('warehouse.rollback_started', $warehouse, ['backup' => $backup->filename, 'tiers' => $backup->tiers], $user->email);
        Log::warning('Rollback to a snapshot started', ['person' => $user->id, 'backup' => $backup->filename]);
        $this->restore->launchSnapshot($backup->filename);

        return response()->json(['message' => 'Rollback started.'], 202);
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
     * Reveal this warehouse's key (what its encrypted values are under; the instance's APP_KEY is not what a restore needs). A POST (never a GET, so it can't be linked, prefetched or land in a URL log) that
     * re-asks for the person's own password, is throttled at the route, never cached, and is logged.
     */
    public function appKey(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        $user = Auth::user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            Log::warning('Warehouse key reveal refused: wrong or missing password', ['person' => $user->id]);

            return response()->json([
                'message' => 'That password is not correct.',
                'errors' => ['password' => [$user->password ? 'That password is not correct.' : 'Your account has no password set, so the key cannot be shown.']],
            ], 422);
        }
        Log::warning('Warehouse key revealed', ['person' => $user->id]);
        InstanceAuditLog::record('warehouse.key_revealed', app(WarehouseContext::class)->current(), [], $user->email);

        return response()->json(['key' => app(WarehouseContext::class)->current()->key()])->header('Cache-Control', 'no-store, private');
    }
}
