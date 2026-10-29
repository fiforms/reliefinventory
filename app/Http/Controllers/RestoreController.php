<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Services\RestoreService;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * First-run wizard's "Restore from a backup" path (/install/restore/*). No login exists yet, so the gate is the
 * wizard's own: every write requires the instance to still need setup (FirstRunService::needsSetup) and, when
 * RI_SETUP_CODE is configured, the code at the start. After that the unguessable upload id is the capability
 * for the chunk calls, exactly as a firmware upload id is. Only the status read stays open afterwards, because
 * the restore itself is what flips needsSetup to false. The work is RestoreService's detached process.
 */
class RestoreController extends Controller
{
    public function __construct(private RestoreService $restore, private FirstRunService $firstRun, private SetupCodes $codes, private WarehouseContext $context, private WarehouseSetup $setup) {}

    /** The same endpoints also serve `/activate/restore/*`: a warehouse awaiting its setup code, for the holder of the code (MULTI_WAREHOUSE.md §7). */
    private function activating(): bool
    {
        return request()->routeIs('activate.restore.*');
    }

    /** `/json/warehouse-restore/*`: a signed-in administrator restoring a backup into their own, set-up warehouse (RestoreService::stageOwnRestore). */
    private function owning(): bool
    {
        return request()->routeIs('warehouse.restore.*');
    }

    private function requireFreshInstance(): void
    {
        if ($this->owning()) {
            $warehouse = $this->context->current();
            abort_unless(Auth::user()?->hasPermission('admin-system'), 403, 'Missing required permission: admin-system');
            abort_unless($warehouse->isActive() && ! $warehouse->is_training, 403, 'This warehouse cannot be restored from here.');

            return;
        }
        if ($this->activating()) {
            $warehouse = $this->context->current();
            abort_unless($warehouse->isAwaitingSetup() && $this->codes->claimed(request(), $warehouse), 403, 'Enter the setup code first.');

            return;
        }
        abort_unless($this->firstRun->needsSetup(), 403, 'This instance has already been set up; a backup can only be restored onto a new one.');
    }

    public function start(Request $request)
    {
        $this->requireFreshInstance();
        $data = $request->validate([
            'filename' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:'.config('backup.max_restore_bytes'),
            'setup_code' => [filled(config('app.setup_code')) && ! $this->activating() && ! $this->owning() ? 'required' : 'nullable', 'string'],
        ]);
        if (! $this->activating() && ! $this->owning() && filled(config('app.setup_code')) && ! hash_equals((string) config('app.setup_code'), (string) $data['setup_code'])) {
            return response()->json(['message' => 'That setup code is not correct.', 'errors' => ['setup_code' => ['That setup code is not correct.']]], 422);
        }
        if ($this->restore->isRunning()) {
            return response()->json(['message' => 'A restore is already running.'], 409);
        }

        @unlink($this->restore->dir().'/status.json'); // a previous failed attempt's message
        $this->restore->clearClaim();
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

    /** The client's "this file is all here" call (sent automatically at the end of every chunked upload). Starts nothing. */
    public function complete(string $id)
    {
        $this->requireFreshInstance();
        $this->assertFullyUploaded($id);

        return response()->json(['message' => 'Upload complete.']);
    }

    /**
     * Start the restore once every file is up: the main backup, plus optionally a separate files backup (for
     * recovering from a damaged database with the best copy of the files — see RestoreService).
     */
    public function launch(Request $request)
    {
        $this->requireFreshInstance();
        $existing = $request->input('login') === 'existing';
        $data = $request->validate([
            'upload_id' => 'required|string',
            'storage_upload_id' => 'nullable|string|different:upload_id',
            'warehouse_key' => ['nullable', 'string', 'max:200', fn ($attribute, $value, $fail) => $value === null || $value === '' || Warehouse::validKey(trim($value)) ?: $fail('That is not a warehouse key: it starts with "base64:" and is 51 characters in all.')],
        ] + ($this->activating() ? WarehouseSetup::personRules($existing) : [])
            + ($this->owning() ? ['password' => ['required', 'string'], 'any_lineage' => ['sometimes', 'boolean']] : []));
        foreach (array_filter([$data['upload_id'], $data['storage_upload_id'] ?? null]) as $id) {
            $this->assertFullyUploaded($id);
        }
        if ($this->restore->isRunning()) {
            return response()->json(['message' => 'A restore is already running.'], 409);
        }
        if ($this->activating()) {
            // Who the new warehouse is for: settled now, because the database replacement that follows wipes the session.
            $warehouse = $this->context->current();
            $account = $this->setup->identity($warehouse, $data);
            $this->restore->stageClaim([
                'code_id' => $this->codes->claimed($request, $warehouse)->id, 'account_id' => $account->id,
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
            ]);
        }
        if ($this->owning()) {
            $user = Auth::user();
            // The database replacement wipes sessions and people alike; the password proves it is the administrator at the keyboard.
            if (! $user->password || ! Hash::check($data['password'], $user->password) || ! $user->account) {
                return response()->json(['message' => 'That password is not correct.', 'errors' => ['password' => ['That password is not correct.']]], 422);
            }
            $anyLineage = (bool) ($data['any_lineage'] ?? false);
            if ($anyLineage && ! $user->account->is_instance_admin) {
                return response()->json(['message' => 'Only an instance administrator can restore another warehouse\'s backup here.'], 403);
            }
            $this->restore->stageOwnRestore([
                'account_id' => $user->account->id, 'first_name' => (string) $user->first_name, 'last_name' => (string) $user->last_name,
                'any_lineage' => $anyLineage,
            ]);
            InstanceAuditLog::record('warehouse.restore_started', $this->context->current(), ['any_lineage' => $anyLineage]);
        }
        $this->restore->clearKeyImport();
        if (! $this->owning() && filled($data['warehouse_key'] ?? null)) {
            $this->restore->stageKeyImport($data['warehouse_key']);   // used only if the warehouse is still fresh when the restore runs
        }
        $this->restore->launch($data['upload_id'], $data['storage_upload_id'] ?? null);

        return response()->json(['message' => 'Restore started.'], 202);
    }

    private function assertFullyUploaded(string $id): void
    {
        $uploads = $this->restore->uploads();
        $meta = $uploads->meta($id);
        $received = $uploads->received($id);
        if ($received !== $meta['size']) {
            abort(response()->json(['message' => "Upload is incomplete ({$received} of {$meta['size']} bytes).", 'received' => $received], 409));
        }
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
        $status = $this->restore->status();
        if (request()->routeIs('restore.status')) {
            // Open to anyone who can reach a warehouse that is being restored (the restorer's session is gone): progress and the outcome, not who the logins are.
            $status = array_intersect_key($status, array_flip(['state', 'phase', 'percent', 'error', 'blanked', 'notes', 'database_restored', 'includes_uploads', 'backup_created_at', 'files_created_at']));
        }

        return response()->json($status)->header('Cache-Control', 'no-store');
    }
}
