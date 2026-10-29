<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\InstanceUpdate;
use App\Models\OfflineModeSetting;
use App\Services\GitVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Backs the System Administration panel (/setup/system): software version /
 * update triggering and offline mode.
 * (Backups — the zip list, the snapshot schedule, rolling back, the app-key panel — are BackupController's.)
 *
 * Platform: config('system.runtime') is 'systemd' (bare metal) or 'docker'. A container is upgraded by the
 * host-side updater (docker/updater/), which this controller only asks (InstanceUpdate); the bare-metal web
 * updater is deprecated. status() says which one this is and leaves out what doesn't apply, and the
 * systemd-only actions refuse in a container (the page doesn't offer them there either).
 *
 * Design notes (see scripts/BACKUPS.md):
 * - A software update is NOT run in-process (composer/npm take minutes and it puts the site into maintenance mode). It is a
 *   dedicated systemd oneshot unit started via a sudoers rule scoped to exactly that command. update.sh reports progress through
 *   a status JSON file this controller reads back.
 * - Backups are not here: bare metal and Docker share one scheduler (BackupSchedule) and one page (Warehouse Backups).
 */
class SystemController extends Controller
{
    /**
     * How long a "running" update status can go without a fresh
     * updated_at before we stop trusting it. update.sh writes a status
     * update within a few seconds of the unit actually starting, and
     * again at completion — a "running" status older than this almost
     * always means the systemd unit never started at all (misconfigured
     * SYSTEM_UPDATE_UNIT, missing sudoers rule, etc.), not a slow build.
     * Without this, a failed hand-off leaves the panel polling "running"
     * forever with no error and, worse, permanently 409-refusing any
     * retry — indistinguishable from a real in-progress update to
     * whoever's looking at it. See TODO.md's system-controller-staleness
     * note for the incident that surfaced this.
     */
    private const RUNNING_STALE_AFTER_SECONDS = 300;

    private function isDocker(): bool
    {
        return config('system.runtime') === 'docker';
    }

    /** The systemd-only actions: null when allowed, else the refusal to return. */
    private function requireSystemd(): ?JsonResponse
    {
        return $this->isDocker()
            ? response()->json(['message' => 'Not available in a Docker install: it is upgraded by the host-side updater.'], 409)
            : null;
    }

    public function status(): JsonResponse
    {
        $docker = $this->isDocker();

        return response()->json([
            'runtime' => $docker ? 'docker' : 'systemd',
            'version' => $this->versionInfo(),
            'update_status' => $docker ? null : $this->readUpdateStatus(),
            // Docker: the self-update flag the host-side updater works from (InstanceUpdate). Only instance admins may use it.
            'docker_update' => $docker ? InstanceUpdate::current()->payload() + ['can_request' => $this->isInstanceAdmin()] : null,
            // The bare-metal web updater is deprecated (see update()); the page says so.
            'updater_deprecated' => ! $docker,
            'offline_mode' => OfflineModeSetting::current()->enabled,
        ]);
    }

    /**
     * One switch for "this warehouse has no reliable internet right now" —
     * see OfflineModeSetting for what it turns off (Turnstile, geocod.io)
     * and why this is a single flag rather than a per-feature checklist.
     */
    public function saveOfflineMode(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        $setting = OfflineModeSetting::current();
        $setting->update($data);

        Log::info('Offline mode changed from admin panel', ['person_id_user' => Auth::id()] + $data);

        return response()->json(['offline_mode' => $setting->enabled]);
    }

    /**
     * Fetch from origin so versionInfo() can see whether we're behind.
     *
     * @deprecated The bare-metal web updater (this, update(), startUnit(), scripts/update.sh and its systemd unit) runs `git pull`,
     *             `artisan migrate` and `npm run build` from a web request, which can leave a half-updated system. It is kept
     *             working for now and will be removed (TODO.md); Docker installs use the host-side updater (InstanceUpdate).
     */
    public function checkUpdates(): JsonResponse
    {
        if ($this->isDocker()) {
            return $this->requestDockerUpdate('check');
        }
        Log::warning('Deprecated bare-metal web update check used', ['person_id_user' => Auth::id()]);
        $result = Process::path(base_path())->timeout(60)->run(['git', 'fetch', 'origin', 'master']);
        if (! $result->successful()) {
            return response()->json([
                'message' => 'Could not reach the update server: '.trim($result->errorOutput()),
            ], 502);
        }

        return response()->json(['version' => $this->versionInfo()]);
    }

    /** @deprecated See checkUpdates(). */
    public function update(): JsonResponse
    {
        if ($this->isDocker()) {
            return $this->requestDockerUpdate('update');
        }
        Log::warning('Deprecated bare-metal web update used', ['person_id_user' => Auth::id()]);
        if (($this->readUpdateStatus()['state'] ?? null) === 'running') {
            return response()->json(['message' => 'An update is already running.'], 409);
        }

        // Optimistic status so the panel shows "running" immediately;
        // update.sh overwrites this as soon as the unit starts.
        file_put_contents(config('system.update_status_file'), json_encode([
            'state' => 'running',
            'message' => 'Update requested; waiting for the updater to start',
            'updated_at' => now()->toIso8601String(),
        ]));

        Log::info('System update triggered from admin panel', ['person_id_user' => Auth::id()]);

        return $this->startUnit(config('system.update_unit'));
    }

    private function isInstanceAdmin(): bool
    {
        return (bool) Auth::user()?->account?->is_instance_admin;
    }

    /**
     * Docker: queue a check or an update for the host-side updater. An update restarts every warehouse on the instance,
     * so it is for instance administrators only, not for whoever holds admin-system in one warehouse.
     */
    private function requestDockerUpdate(string $action): JsonResponse
    {
        if (! $this->isInstanceAdmin()) {
            return response()->json(['message' => 'Only an instance administrator can check for or install updates.'], 403);
        }
        if (! InstanceUpdate::enabled()) {
            return response()->json(['message' => 'Automatic updates are not set up: set RI_UPDATE_SECRET in compose.yaml and run the updater on the host (docker/updater/).'], 409);
        }
        if (! InstanceUpdate::request($action, Auth::user()->email)) {
            return response()->json(['message' => 'An update is already in progress.'], 409);
        }

        return response()->json(['docker_update' => InstanceUpdate::current()->payload() + ['can_request' => true]], 202);
    }

    /** @deprecated See checkUpdates(). */
    private function startUnit(string $unit): JsonResponse
    {
        $result = Process::timeout(15)->run(['sudo', '-n', '/usr/bin/systemctl', 'start', '--no-block', $unit]);
        if (! $result->successful()) {
            return response()->json([
                'message' => "Could not start $unit — is the sudoers rule from scripts/systemd/reliefinventory-sudoers installed? ".trim($result->errorOutput()),
            ], 500);
        }

        return response()->json(['message' => 'started']);
    }

    private function git(string ...$args): ?string
    {
        $result = Process::path(base_path())->timeout(20)->run(array_merge(['git'], $args));

        return $result->successful() ? trim($result->output()) : null;
    }

    private function versionInfo(): array
    {
        // A container has no git checkout and nothing to fetch: just the commit the image was built from.
        if ($this->isDocker()) {
            return [
                'current' => app(GitVersionService::class)->currentCommit(),
                'release' => app(GitVersionService::class)->release(),
                'current_subject' => null, 'current_date' => null, 'behind' => null, 'pending_commits' => [],
            ];
        }

        $behind = $this->git('rev-list', '--count', 'HEAD..origin/master');
        $pending = $this->git('log', '--oneline', '--no-decorate', '-20', 'HEAD..origin/master');

        return [
            'current' => $this->git('rev-parse', '--short=9', 'HEAD'),
            'release' => app(GitVersionService::class)->release(),
            'current_subject' => $this->git('log', '-1', '--format=%s', 'HEAD'),
            'current_date' => $this->git('log', '-1', '--format=%cI', 'HEAD'),
            // Relative to the last fetch — "Check for updates" refreshes this.
            'behind' => $behind === null ? null : (int) $behind,
            'pending_commits' => $pending ? explode("\n", $pending) : [],
        ];
    }

    private function readUpdateStatus(): ?array
    {
        $path = config('system.update_status_file');
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        if (($decoded['state'] ?? null) === 'running' && ! empty($decoded['updated_at'])) {
            $secondsSinceUpdate = abs(now()->diffInSeconds(Carbon::parse($decoded['updated_at'])));
            if ($secondsSinceUpdate > self::RUNNING_STALE_AFTER_SECONDS) {
                // Computed on read, never written back — a later real
                // status write (from an update that does start) simply
                // supersedes this the next time the file is read.
                $decoded['state'] = 'stalled';
                $decoded['message'] = 'No progress in over '.intdiv(self::RUNNING_STALE_AFTER_SECONDS, 60)
                    .' minutes (last status: "'.$decoded['message'].'"). The update most likely never '
                    .'started — check SYSTEM_UPDATE_UNIT in .env and the sudoers rule on the server, then try again.';
            }
        }

        return $decoded;
    }
}
