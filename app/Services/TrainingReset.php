<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Http\Controllers\TrainingAdminController;
use App\Http\Controllers\TrainingReportController;
use App\Models\TrainingSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Training Mode's reset: put the instance back the way it was before the class.
 *
 *  - Turning Training Mode on first takes a **reset point**: a database-only backup (BackupService::createIn) in
 *    <backup dir>/training, one at a time (a new one replaces the old). Its file name and time are written to
 *    training_settings *after* the backup, so the restored database doesn't carry them.
 *  - "Turn off and reset" first saves a **class archive** — the sign-ups follow-up list, report cards (PDF + CSV) and
 *    certificates — to the local disk under training-archives/<stamp>/ (files, so restoring the database leaves it), then
 *    starts `training:reset` detached. That restores the reset point with RestoreService (maintenance mode meanwhile,
 *    left on if the load fails — the same rules as `backup:restore --force`) and deletes the zip once it worked.
 *
 * Everything entered in the database after the reset point is gone afterwards: practice records, trainees, sign-ups,
 * the activity log, items added to the catalog and devices approved since. Uploaded files are untouched. Progress
 * lives in <backup dir>/training/reset-status.json, since the database is replaced part-way.
 */
class TrainingReset
{
    public const ARCHIVE_DIR = 'training-archives';

    /** The files a class archive may hold (also the only names the download route serves). */
    public const ARCHIVE_FILES = [
        'signups.csv' => 'Sign-up follow-up list (CSV)',
        'report-cards.pdf' => 'Report cards (PDF)',
        'report-cards.csv' => 'Report cards (CSV)',
        'certificates.pdf' => 'Certificates (PDF)',
    ];

    public function dir(): string
    {
        return rtrim(config('backup.dir'), '/').'/training';
    }

    /** Take a new reset point, replacing any earlier one. Throws RuntimeException with the reason. */
    public function takeResetPoint(): void
    {
        $settings = TrainingSetting::current();
        $name = 'training-reset-point-'.now()->format('Ymd-His');
        $result = app(BackupService::class)->createIn($this->dir(), 'database', $name);
        $old = $this->resetPointPath();
        $settings->update(['reset_point_file' => basename($result['path']), 'reset_point_at' => now()]);
        if ($old && $old !== $result['path']) {
            @unlink($old);
        }
    }

    /** The reset point zip, if one was taken and is still on disk. */
    public function resetPointPath(): ?string
    {
        $file = TrainingSetting::current()->reset_point_file;
        $path = $file ? $this->dir().'/'.basename($file) : null;

        return $path && is_file($path) ? $path : null;
    }

    public function resetPoint(): ?array
    {
        $path = $this->resetPointPath();

        return $path ? ['at' => TrainingSetting::current()->reset_point_at, 'bytes' => filesize($path)] : null;
    }

    public function status(): array
    {
        $raw = @file_get_contents($this->dir().'/reset-status.json');

        return ($raw !== false ? json_decode($raw, true) : null) ?: ['state' => 'idle'];
    }

    /** A reset whose process died long ago (no status written for 30 minutes) no longer blocks another. */
    public function isRunning(): bool
    {
        $status = $this->status();
        $running = $status['state'] === 'running'
            && now()->diffInMinutes(Carbon::parse($status['started_at'] ?? 0), true) < 30;

        return $running || app(RestoreService::class)->isRunning();
    }

    /** Save the class archive, then start the detached reset. Returns the archive's stamp. */
    public function start(): string
    {
        $zip = $this->resetPointPath();
        if (! $zip) {
            throw new RuntimeException('There is no reset point: Training Mode was turned on without one, or it has already been used.');
        }
        if ($this->isRunning()) {
            throw new RuntimeException('A reset or restore is already running.');
        }

        $stamp = $this->archive();
        $this->writeStatus(['state' => 'running', 'started_at' => now()->toIso8601String(), 'archive' => $stamp]);
        $this->launch(basename($zip), $stamp);

        return $stamp;
    }

    /** Starts `training:reset` in its own session, so it outlives the request (and the maintenance mode it turns on). */
    protected function launch(string $file, string $stamp): void
    {
        $log = escapeshellarg($this->dir().'/reset.log');
        $php = escapeshellarg(config('backup.php_binary'));
        Process::path(base_path())->run("setsid -f {$php} artisan training:reset ".escapeshellarg($file).' '.escapeshellarg($stamp)." >> {$log} 2>&1 < /dev/null");
    }

    /** The detached half (training:reset): restore the reset point. */
    public function run(string $file, string $stamp): void
    {
        $zip = $this->dir().'/'.basename($file);
        try {
            app(RestoreService::class)->runFromFiles($zip, null, true);
            @unlink($zip);
            $this->writeStatus(['state' => 'complete', 'finished_at' => now()->toIso8601String(), 'archive' => $stamp]);
        } catch (Throwable $e) {
            Log::error('Training reset failed', ['message' => $e->getMessage()]);
            $this->writeStatus(['state' => 'failed', 'finished_at' => now()->toIso8601String(), 'archive' => $stamp, 'error' => $e->getMessage(),
                'reset_point' => is_file($zip) ? $zip : null]);
        }
    }

    /**
     * The class archive: what is worth keeping from the class, as files. Report cards cover the days from the reset
     * point to today. A part that fails to render is left out rather than stopping the reset; the sign-ups always go.
     */
    public function archive(): string
    {
        $stamp = now()->format('Y-m-d_His');
        $dir = self::ARCHIVE_DIR.'/'.$stamp;
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);
        $disk->put("$dir/signups.csv", TrainingAdminController::signupsCsvString());

        $from = (TrainingSetting::current()->reset_point_at ?? TrainingSetting::current()->enabled_at ?? now())->toDateString();
        $filters = ['from' => min($from, now()->toDateString()), 'to' => now()->toDateString()];
        $reports = app(TrainingReportController::class);
        $parts = [
            'report-cards.csv' => fn () => $disk->put("$dir/report-cards.csv", $reports->csvString($filters)),
            'report-cards.pdf' => fn () => $reports->pdfBuilder($filters)->save($disk->path("$dir/report-cards.pdf")),
            'certificates.pdf' => fn () => $reports->certificatesBuilder($filters)->save($disk->path("$dir/certificates.pdf")),
        ];
        foreach ($parts as $name => $make) {
            try {
                $make();
            } catch (Throwable $e) {
                Log::warning("Training archive: could not save $name", ['message' => $e->getMessage()]);
            }
        }

        return $stamp;
    }

    /** @return array<int, array{stamp: string, files: array<int, array{name: string, label: string}>}> newest first */
    public function archives(): array
    {
        $disk = Storage::disk('local');
        $out = [];
        foreach ($disk->directories(self::ARCHIVE_DIR) as $path) {
            $stamp = basename($path);
            $files = [];
            foreach (self::ARCHIVE_FILES as $name => $label) {
                if ($disk->exists("$path/$name")) {
                    $files[] = ['name' => $name, 'label' => $label];
                }
            }
            $out[] = ['stamp' => $stamp, 'files' => $files];
        }
        usort($out, fn ($a, $b) => strcmp($b['stamp'], $a['stamp']));

        return $out;
    }

    /** The disk path of one archived file, or null (only known names in a well-formed stamp). */
    public function archiveFile(string $stamp, string $name): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $stamp) || ! isset(self::ARCHIVE_FILES[$name])) {
            return null;
        }
        $path = self::ARCHIVE_DIR."/$stamp/$name";

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    private function writeStatus(array $status): void
    {
        @mkdir($this->dir(), 0750, true);
        file_put_contents($this->dir().'/reset-status.json', json_encode($status));
    }
}
