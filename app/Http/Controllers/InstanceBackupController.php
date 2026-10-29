<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceBackupSchedule;
use App\Models\Control\InstanceTrust;
use App\Models\Control\ProvisioningCommand;
use App\Services\Federation\InstanceTrustService;
use App\Services\InstanceBackupService;
use App\Services\Warehouses\ProvisioningClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Full Instance Backups on Instance Admin (/setup/instance, instance administrators only): the schedule, the list, "Back up now" and
 * download. The web container cannot make the backup (it needs the database administrator's password, which only the provisioner holds),
 * so "now" writes a fixed provisioning request (`backup-now` / `backup-now-files`: no parameters, no keys) and the page polls the outcome.
 * Every change re-asks for the signed-in person's password (ConfirmsPassword). Deleting a zip is not here: the zips belong to the
 * provisioner's user, so an operator removes them at the server. Docker only (there is no provisioner elsewhere).
 */
class InstanceBackupController extends Controller
{
    use ConfirmsPassword;

    /** The requests this page may make, and the only ones it reports on. */
    private const BACKUP_COMMANDS = ['backup-sched', 'backup-now', 'backup-now-files'];

    public function __construct(private InstanceBackupService $backups) {}

    public function index(): JsonResponse
    {
        if (! ProvisioningClient::applies()) {
            return response()->json(['available' => false]);
        }

        $dir = $this->backups->dir();
        $zips = array_map(fn (array $z) => $z + ['downloadable' => is_readable("{$dir}/{$z['name']}")], $this->backups->listing());
        $activity = ProvisioningCommand::whereIn('command', self::BACKUP_COMMANDS)->where('requested_by', '!=', 'scheduler')->latest('id')->first();

        return response()->json([
            'available' => true,
            'schedule' => InstanceBackupSchedule::current()->toArray(),
            'backups' => $zips,
            'activity' => $activity ? ['command' => $activity->command, 'status' => $activity->status, 'message' => $activity->message, 'finished_at' => $activity->finished_at?->toIso8601String()] : null,
            'working' => ProvisioningCommand::whereIn('command', self::BACKUP_COMMANDS)->whereIn('status', ['pending', 'running'])->exists(),
            'address' => request()->getSchemeAndHttpHost(),
            'clients' => InstanceTrust::orderByDesc('id')->get()->map(fn (InstanceTrust $t) => [
                'id' => $t->id, 'name' => $t->name, 'status' => $t->status,
                'approved_at' => $t->approved_at?->toIso8601String(), 'revoked_at' => $t->revoked_at?->toIso8601String(),
                'last_seen_at' => $t->last_seen_at?->toIso8601String(), 'last_seen_ip' => $t->last_seen_ip,
            ])->values(),
        ]);
    }

    /**
     * A one-time code (15 minutes, shown once, only its hash kept) for a computer to pair as an InstanceTrust that may take whole-instance
     * backups: the RI Launcher's "Pair" asks for the instance's address and this code.
     */
    public function pairingCode(Request $request): JsonResponse
    {
        $data = $request->validate(['name_hint' => 'nullable|string|max:100']);

        return $this->confirmed($request, 'instance-trust-code', function () use ($data) {
            $this->requireDocker();
            [$code, $row] = InstanceTrustService::createPairingCode($data['name_hint'] ?? null, Auth::user()->email);

            return ['code' => $code, 'expires_at' => $row->expires_at->toIso8601String(), 'address' => request()->getSchemeAndHttpHost()];
        });
    }

    /** Stop a paired computer at once (its next request is a 401 and the Launcher asks to be paired again). */
    public function revokeClient(Request $request, int $id): JsonResponse
    {
        return $this->confirmed($request, 'instance-trust-revoke', function () use ($id) {
            $trust = InstanceTrust::find($id) ?? throw new RuntimeException('That computer is not paired.');
            InstanceTrustService::revoke($trust, Auth::user()->email);

            return ['message' => "{$trust->name} can no longer make instance backups."];
        });
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'daily_hour' => 'required|integer|min:0|max:23', 'daily_keep' => 'required|integer|min:1|max:90', 'daily_with_files' => 'required|boolean',
            'weekly_enabled' => 'required|boolean', 'weekly_dow' => 'required|integer|min:0|max:6',
            'weekly_keep' => 'required|integer|min:1|max:52', 'weekly_with_files' => 'required|boolean',
        ]);

        return $this->confirmed($request, 'instance-backup-schedule', function () use ($data) {
            $this->requireDocker();
            InstanceBackupSchedule::current()->update($data);
            InstanceAuditLog::record('instance.backup_schedule_changed', null, $data, Auth::user()->email);

            return ['message' => 'Schedule saved.', 'schedule' => InstanceBackupSchedule::current()->toArray()];
        });
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['files' => 'required|boolean']);

        return $this->confirmed($request, 'instance-backup-now', function () use ($data) {
            $this->requireDocker();
            if (ProvisioningCommand::whereIn('command', self::BACKUP_COMMANDS)->whereIn('status', ['pending', 'running'])->exists()) {
                throw new RuntimeException('An instance backup is already being made.');
            }
            $command = $data['files'] ? 'backup-now-files' : 'backup-now';
            ProvisioningCommand::create(['command' => $command, 'requested_by' => Auth::user()->email]);
            InstanceAuditLog::record('instance.backup_requested', null, ['files' => $data['files']], Auth::user()->email);
            Log::notice('Instance backup requested', ['person' => Auth::id(), 'files' => $data['files']]);

            return ['message' => 'The backup was requested. A large one can take a while; this page shows when it is done.'];
        });
    }

    /** Served only if the web container can read the zip (the provisioner writes them 0600 as its own user); the name must be one this service wrote. */
    public function download(string $name)
    {
        abort_unless(ProvisioningClient::applies() && InstanceBackupService::isBackupName($name), 404);
        $path = $this->backups->dir().'/'.$name;
        abort_unless(is_file($path), 404);
        abort_unless(is_readable($path), 403, 'The web server cannot read this file. Copy it from the server (the backup folder, instance/) or use the RI Launcher.');
        InstanceAuditLog::record('instance.backup_downloaded', null, ['file' => $name], Auth::user()->email);
        Log::notice('Instance backup downloaded', ['file' => $name, 'person' => Auth::id()]);

        return response()->download($path, $name, ['Cache-Control' => 'no-store']);
    }

    private function requireDocker(): void
    {
        if (! ProvisioningClient::applies()) {
            throw new RuntimeException('Instance backups need the Docker install (they are made by the provisioner container).');
        }
    }
}
