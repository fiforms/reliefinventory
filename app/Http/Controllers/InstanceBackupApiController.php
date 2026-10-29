<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceTrust;
use App\Models\Control\ProvisioningCommand;
use App\Services\InstanceBackupService;
use App\Services\Warehouses\ProvisioningClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Whole-instance backups for the RI Launcher (`/api/instance/v1/backups`, the `instance.backup` grant of an InstanceTrust). The same shape
 * as FederationBackupController's warehouse backups (list, ask for a new one, watch it, download it with Range, whole-file SHA-256), so the
 * Launcher's runner works on either, but the zips are the provisioner's (InstanceBackupService): asking writes the fixed `backup-now` /
 * `backup-now-files` request and the id it returns is that request's id.
 *
 * Ids: a request in flight is its (small) provisioning id; a finished zip is its 14-digit stamp, YmdHis from its file name (ids never exceed
 * what a signed 64-bit number holds, and a stamp is far above any request id). Both resolve to the same file once the request is done, so an id
 * the Launcher got when it asked still downloads. Nothing from a URL ever names a path: a file is found by listing the backup folder.
 */
class InstanceBackupApiController extends Controller
{
    /** Request ids stay well below this; stamps (14 digits) are above. */
    private const STAMP_FLOOR = 10_000_000_000_000;

    private const COMMANDS = ['backup-now', 'backup-now-files'];

    public function __construct(private InstanceBackupService $backups) {}

    public function index(): JsonResponse
    {
        $rows = collect($this->backups->listing())->map(fn (array $z) => $this->presentZip($z));
        $pending = ProvisioningCommand::whereIn('command', self::COMMANDS)->whereIn('status', ['pending', 'running', 'failed'])->latest('id')->limit(5)->get()
            ->map(fn (ProvisioningCommand $c) => $this->presentCommand($c));

        return response()->json(['backups' => $pending->concat($rows)->take(100)->values(), 'keep_days' => null]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! ProvisioningClient::applies()) {
            return response()->json(['message' => 'Instance backups need the Docker install.'], 422);
        }
        $data = $request->validate(['scope' => 'required|in:database,full']);
        $trust = $request->attributes->get('instance_trust');

        // One at a time (the provisioner works one request at a time anyway): say which one is running so the caller waits for it.
        $active = ProvisioningCommand::whereIn('command', [...self::COMMANDS, 'backup-sched'])->whereIn('status', ['pending', 'running'])->orderBy('id')->first();
        if ($active) {
            return response()->json(['message' => 'An instance backup is already being made.', 'backup' => $this->presentCommand($active)], 409);
        }

        // refresh(): the row's status is the column default, which the new model doesn't hold until it is read back.
        $command = ProvisioningCommand::create(['command' => $data['scope'] === 'full' ? 'backup-now-files' : 'backup-now', 'requested_by' => 'launcher: '.$trust->name])->refresh();
        InstanceAuditLog::record('instance.backup_requested', null, ['scope' => $data['scope'], 'trust' => $trust->id, 'name' => $trust->name], 'launcher: '.$trust->name);
        Log::notice('Instance backup requested by a trusted computer', ['request' => $command->id, 'trust' => $trust->id, 'scope' => $data['scope']]);

        return response()->json(['backup' => $this->presentCommand($command)], 202);
    }

    public function show(int $id): JsonResponse
    {
        if ($id < self::STAMP_FLOOR) {
            $command = ProvisioningCommand::whereKey($id)->whereIn('command', self::COMMANDS)->firstOrFail();

            return response()->json(['backup' => $this->presentCommand($command, withHash: true)]);
        }
        $zip = $this->zipByStamp($id) ?? abort(404);

        return response()->json(['backup' => $this->presentZip($zip, withHash: true)]);
    }

    public function download(Request $request, int $id)
    {
        $name = $this->fileName($id) ?? abort(404);
        $path = $this->backups->dir().'/'.$name;
        abort_unless(is_file($path), 404);
        abort_unless(is_readable($path), 503, 'The web server cannot read this backup.');

        // A resumed transfer is the same download; only its start is worth an audit row.
        $range = (string) $request->header('Range');
        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            $trust = $request->attributes->get('instance_trust');
            InstanceAuditLog::record('instance.backup_downloaded', null, ['file' => $name, 'trust' => $trust->id, 'name' => $trust->name], 'launcher: '.$trust->name);
            Log::notice('Instance backup downloaded by a trusted computer', ['file' => $name, 'trust' => $trust->id]);
        }

        return response()->download($path, $name, ['Cache-Control' => 'no-store']);
    }

    /** The file an id means: a stamp's zip, or the zip a finished request wrote. */
    private function fileName(int $id): ?string
    {
        if ($id >= self::STAMP_FLOOR) {
            return $this->zipByStamp($id)['name'] ?? null;
        }
        $command = ProvisioningCommand::whereKey($id)->whereIn('command', self::COMMANDS)->where('status', 'done')->first();

        return $command ? $this->nameFromMessage($command) : null;
    }

    /** @return array{name: string, size: int, made_at: string, scope: string, label: ?string}|null */
    private function zipByStamp(int $stamp): ?array
    {
        foreach ($this->backups->listing() as $zip) {
            if ($this->stamp($zip) === $stamp) {
                return $zip;
            }
        }

        return null;
    }

    private function stamp(array $zip): int
    {
        return (int) preg_replace('/\D/', '', substr($zip['name'], strlen('relief-instance-'), 15));
    }

    /** The provisioner's answer names the zip it wrote; only a name this service would write is believed. */
    private function nameFromMessage(ProvisioningCommand $command): ?string
    {
        if (! preg_match('/(relief-instance-[A-Za-z0-9._-]+\.zip)/', (string) $command->message, $m) || ! InstanceBackupService::isBackupName($m[1])) {
            return null;
        }

        return $m[1];
    }

    private function presentZip(array $zip, bool $withHash = false): array
    {
        $path = $this->backups->dir().'/'.$zip['name'];

        return [
            'id' => $this->stamp($zip), 'scope' => $zip['scope'], 'status' => 'complete', 'progress' => null,
            'filename' => $zip['name'], 'size_bytes' => $zip['size'], 'file_count' => null,
            'created_at' => $zip['made_at'], 'finished_at' => $zip['made_at'], 'expires_at' => null, 'error' => null,
            'label' => $zip['label'],
            'sha256' => $withHash ? $this->sha256($path) : null,
        ];
    }

    private function presentCommand(ProvisioningCommand $c, bool $withHash = false): array
    {
        $name = $c->status === 'done' ? $this->nameFromMessage($c) : null;
        $path = $name ? $this->backups->dir().'/'.$name : null;
        $ready = $path && is_file($path);
        $status = match ($c->status) {
            'pending' => 'queued',
            'running' => 'running',
            'failed' => 'failed',
            default => $ready ? 'complete' : 'missing',   // done, but the zip is gone (or the answer named none)
        };

        return [
            'id' => $c->id, 'scope' => $c->command === 'backup-now-files' ? 'full' : 'database', 'status' => $status,
            'progress' => in_array($status, ['queued', 'running'], true) ? ($status === 'queued' ? 'Waiting for the provisioner' : 'Making the backup') : null,
            'filename' => $name, 'size_bytes' => $ready ? (int) filesize($path) : null, 'file_count' => null,
            'created_at' => $c->created_at?->toIso8601String(), 'finished_at' => $c->finished_at?->toIso8601String(), 'expires_at' => null,
            'error' => $c->status === 'failed' ? $c->message : null,
            'sha256' => $withHash && $ready ? $this->sha256($path) : null,
        ];
    }

    private function sha256(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        return Cache::rememberForever('instance-backup-sha256:'.basename($path).':'.filesize($path).':'.filemtime($path), fn () => hash_file('sha256', $path));
    }
}
