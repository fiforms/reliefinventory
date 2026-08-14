<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Backs the System Administration panel (/setup/system): software version /
 * update triggering, backup inventory, and the backup schedule.
 *
 * Design notes (see scripts/BACKUPS.md):
 * - Backup settings live in a www-data-writable conf file that
 *   scripts/update.sh parses with strict validation; this controller is the
 *   writer, the script is the reader. Validation here mirrors the script's.
 * - Updates and on-demand backups are NOT run in-process (composer/npm take
 *   minutes and a full update puts the site into maintenance mode). They are
 *   dedicated systemd oneshot units started via a sudoers rule scoped to
 *   exactly those two commands. update.sh reports progress through a status
 *   JSON file this controller reads back.
 */
class SystemController extends Controller
{
    private const SETTINGS_DEFAULTS = [
        'frequency' => 'daily',
        'hour' => 2,
        'dow' => 7,
        'tz' => 'America/Los_Angeles',
        'keep_daily' => 14,
        'keep_monthly' => 12,
        'keep_yearly' => 3,
    ];

    private const SETTINGS_FILE_KEYS = [
        'frequency' => 'BACKUP_FREQUENCY',
        'hour' => 'BACKUP_HOUR',
        'dow' => 'BACKUP_DOW',
        'tz' => 'BACKUP_TZ',
        'keep_daily' => 'KEEP_DAILY',
        'keep_monthly' => 'KEEP_MONTHLY',
        'keep_yearly' => 'KEEP_YEARLY',
    ];

    public function status(): JsonResponse
    {
        return response()->json([
            'version' => $this->versionInfo(),
            'backup_settings' => $this->readBackupSettings(),
            'backups' => $this->backupInventory(),
            'update_status' => $this->readUpdateStatus(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Fetch from origin so versionInfo() can see whether we're behind.
     */
    public function checkUpdates(): JsonResponse
    {
        $result = Process::path(base_path())->timeout(60)->run(['git', 'fetch', 'origin', 'master']);
        if (! $result->successful()) {
            return response()->json([
                'message' => 'Could not reach the update server: '.trim($result->errorOutput()),
            ], 502);
        }

        return response()->json(['version' => $this->versionInfo()]);
    }

    public function update(): JsonResponse
    {
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

    public function backupNow(): JsonResponse
    {
        Log::info('On-demand backup triggered from admin panel', ['person_id_user' => Auth::id()]);

        return $this->startUnit(config('system.backup_now_unit'));
    }

    public function saveBackupSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'frequency' => 'required|in:daily,weekly',
            'hour' => 'required|integer|between:0,23',
            'dow' => 'required|integer|between:1,7',
            'tz' => 'required|timezone:all',
            'keep_daily' => 'required|integer|between:1,365',
            'keep_monthly' => 'required|integer|between:0,120',
            'keep_yearly' => 'required|integer|between:0,50',
        ]);

        $lines = ['# Relief Inventory backup settings — managed by the System Administration panel.',
            '# Format reference: scripts/backup-settings.conf.example'];
        foreach (self::SETTINGS_FILE_KEYS as $field => $fileKey) {
            $lines[] = $fileKey.'='.$data[$field];
        }
        file_put_contents(config('system.settings_file'), implode("\n", $lines)."\n");

        Log::info('Backup settings changed from admin panel', ['person_id_user' => Auth::id()] + $data);

        return response()->json(['backup_settings' => $this->readBackupSettings()]);
    }

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
        $behind = $this->git('rev-list', '--count', 'HEAD..origin/master');
        $pending = $this->git('log', '--oneline', '--no-decorate', '-20', 'HEAD..origin/master');

        return [
            'current' => $this->git('rev-parse', '--short=9', 'HEAD'),
            'current_subject' => $this->git('log', '-1', '--format=%s', 'HEAD'),
            'current_date' => $this->git('log', '-1', '--format=%cI', 'HEAD'),
            // Relative to the last fetch — "Check for updates" refreshes this.
            'behind' => $behind === null ? null : (int) $behind,
            'pending_commits' => $pending ? explode("\n", $pending) : [],
        ];
    }

    private function readBackupSettings(): array
    {
        $values = self::SETTINGS_DEFAULTS;
        $path = config('system.settings_file');
        if (is_file($path)) {
            $byFileKey = array_flip(self::SETTINGS_FILE_KEYS);
            foreach (file($path) as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m) && isset($byFileKey[$m[1]])) {
                    $values[$byFileKey[$m[1]]] = trim($m[2], " \t\"'");
                }
            }
        }
        foreach (['hour', 'dow', 'keep_daily', 'keep_monthly', 'keep_yearly'] as $intField) {
            $values[$intField] = (int) $values[$intField];
        }

        return $values;
    }

    /**
     * Backup directory contents are root-only (they hold credentials), but the
     * tier directories themselves are listable, which is all the panel needs.
     */
    private function backupInventory(): array
    {
        $dir = config('system.backup_dir');
        $tiers = [];
        foreach (['daily', 'monthly', 'yearly'] as $tier) {
            $entries = is_dir("$dir/$tier")
                ? collect(scandir("$dir/$tier"))->filter(fn ($n) => preg_match('/^\d{8}-\d{6}$/', $n))->sort()->values()
                : collect();
            $tiers[$tier] = [
                'count' => $entries->count(),
                'entries' => $entries->reverse()->take(5)->values(),
            ];
        }

        $lastScheduled = @file_get_contents("$dir/.last-scheduled");
        $freeBytes = is_dir($dir) ? @disk_free_space($dir) : null;

        return [
            'tiers' => $tiers,
            'last_scheduled' => $lastScheduled ? trim($lastScheduled) : null,
            'disk_free_bytes' => $freeBytes === false ? null : $freeBytes,
        ];
    }

    private function readUpdateStatus(): ?array
    {
        $path = config('system.update_status_file');
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
