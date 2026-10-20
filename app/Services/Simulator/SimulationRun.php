<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Simulator;

use App\Models\SetupState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Runs the warehouse simulator and keeps its progress in a status file (not
 * the database: during a run the simulator holds one open transaction, and
 * the whole point is that the status is readable from another request).
 *
 * start() launches `simulate:warehouse` in a detached process — same
 * setsid pattern as BackupService, so it outlives the web request, needs no
 * queue worker, and the first-run wizard can hand off to it and carry on.
 */
class SimulationRun
{
    public static function statusPath(): string
    {
        return storage_path('app/simulator-status.json');
    }

    /** @return array<string, mixed> state: none|running|done|failed */
    public function status(): array
    {
        $status = ['state' => 'none'];
        if (File::exists(self::statusPath())) {
            $status = json_decode((string) File::get(self::statusPath()), true) ?: $status;
        }

        // A crashed/killed process would otherwise read "running" forever.
        if (($status['state'] ?? null) === 'running' && ! $this->alive($status['pid'] ?? null)) {
            $status = ['state' => 'failed', 'error' => 'The simulation process stopped unexpectedly. Nothing was saved; check storage/logs/simulator.log.'] + $status;
            $this->write($status);
        }

        return $status;
    }

    public function isRunning(): bool
    {
        return ($this->status()['state'] ?? null) === 'running';
    }

    /** Launch the detached run; returns false if one is already going. */
    public function start(int $days = 365, int $orders = 1200, ?int $seed = null, int $errors = 4): bool
    {
        if ($this->isRunning()) {
            return false;
        }

        $this->write(['state' => 'running', 'day' => 0, 'total' => $days, 'message' => 'Starting…', 'started_at' => now()->toIso8601String(), 'pid' => null]);

        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(storage_path('logs/simulator.log'));
        $args = "--yes --days={$days} --orders={$orders} --errors={$errors}".($seed !== null ? " --seed={$seed}" : '');
        Process::path(base_path())->run("setsid -f {$php} artisan simulate:warehouse {$args} >> {$log} 2>&1 < /dev/null");

        return true;
    }

    /**
     * Run in this process (the artisan command calls this, foreground or as
     * the detached child). Always leaves a final status behind.
     *
     * @return array<string, mixed> the simulator's summary
     */
    public function execute(int $days, int $orders, ?int $seed, int $errors, ?callable $onProgress = null): array
    {
        $startedAt = now()->toIso8601String();
        $this->write(['state' => 'running', 'day' => 0, 'total' => $days, 'message' => 'Starting…', 'started_at' => $startedAt, 'pid' => getmypid()]);

        try {
            $lastWrite = 0.0;
            $summary = app(WarehouseSimulator::class)->run($days, $orders, $seed, $errors, function (int $day, int $total, string $message) use (&$lastWrite, $startedAt, $onProgress) {
                $onProgress?->__invoke($day, $total, $message);
                // Not every day: ~2 writes a second is plenty for a progress bar.
                if (microtime(true) - $lastWrite > 0.5) {
                    $lastWrite = microtime(true);
                    $this->write(['state' => 'running', 'day' => $day, 'total' => $total, 'message' => "Simulating {$message}", 'started_at' => $startedAt, 'pid' => getmypid()]);
                }
            });

            SetupState::current()->forceFill(['demo_data_seeded_at' => now()])->save();
            $this->write(['state' => 'done', 'day' => $days, 'total' => $days, 'message' => 'Done', 'started_at' => $startedAt, 'finished_at' => now()->toIso8601String(), 'summary' => $summary]);

            return $summary;
        } catch (Throwable $e) {
            report($e);
            $this->write(['state' => 'failed', 'started_at' => $startedAt, 'finished_at' => now()->toIso8601String(), 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * The detached command refused to start (guard, bad options) before execute() ran:
     * replace the launcher's "Starting…" with the reason so the progress page can show it.
     */
    public function markStartRefused(string $reason): void
    {
        $raw = $this->raw();
        if (($raw['state'] ?? null) === 'running' && empty($raw['pid'])) {
            $this->write(['state' => 'failed', 'error' => $reason, 'started_at' => $raw['started_at'] ?? null, 'finished_at' => now()->toIso8601String()]);
        }
    }

    private function alive(mixed $pid): bool
    {
        if (! $pid) {
            // Just launched; the child writes its pid as its first act. Give it a moment.
            $started = strtotime((string) ($this->raw()['started_at'] ?? '')) ?: 0;

            return time() - $started < 30;
        }

        return is_dir("/proc/{$pid}") || (function_exists('posix_kill') && @posix_kill((int) $pid, 0));
    }

    private function raw(): array
    {
        return File::exists(self::statusPath()) ? (json_decode((string) File::get(self::statusPath()), true) ?: []) : [];
    }

    private function write(array $status): void
    {
        File::ensureDirectoryExists(dirname(self::statusPath()));
        File::put(self::statusPath(), json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true);
    }
}
