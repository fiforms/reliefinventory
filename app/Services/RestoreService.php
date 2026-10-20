<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restore a backup zip (BackupService's format) onto a brand-new instance — the first-run wizard's only
 * restore path. Creating or emptying an instance is deliberately not offered anywhere in the UI.
 *
 * Phases, in the order that keeps a bad file harmless:
 *   1. read + validate the manifest (BackupCompatibility), check free disk space
 *   2. extract every entry to a work directory, verifying size and SHA-256 as it streams
 *      — nothing on the instance has changed up to here, so any failure leaves it untouched
 *   3. wipe the (empty, never-set-up) database, load database/db.sql, run migrations
 *   4. move uploaded files into place
 *   5. blank values encrypted with a key this instance doesn't have (config backup.encrypted_columns)
 *   6. clear machine-local state that points at files a backup never carries, set the setup-complete latch
 *
 * The database is replaced in step 3, so progress can't live in it: it lives in <backup dir>/restore/status.json
 * and the wizard polls that. Like BackupService this runs as a detached process (restore:run), one at a time
 * via an flock. If step 3 fails after the wipe, the instance is put back to a clean migrated + seeded state
 * so the wizard is usable again.
 */
class RestoreService
{
    private const CHUNK = 1024 * 1024;

    public function dir(): string
    {
        return rtrim(config('backup.dir'), '/').'/restore';
    }

    public function uploads(): ChunkedUpload
    {
        @mkdir($this->dir(), 0750, true);

        return new ChunkedUpload($this->dir());
    }

    private function statusPath(): string
    {
        return $this->dir().'/status.json';
    }

    public function status(): array
    {
        $file = $this->statusPath();
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($status)) {
            return ['state' => 'idle'];
        }
        // A "running" status with nobody holding the lock means the process died (crash, container restart) —
        // but not in the first moments, before the freshly launched process has taken the lock.
        $stale = now()->subSeconds(30)->gt(Carbon::parse($status['updated_at'] ?? now()));
        if ($status['state'] === 'running' && $stale && ! $this->lockHeld()) {
            $status = ['state' => 'failed', 'error' => 'The restore process stopped before finishing (server restart or crash). The instance was not changed unless the database step had begun; reload and try again.'];
            $this->writeStatus($status);
        }

        return $status;
    }

    private function writeStatus(array $status): void
    {
        @mkdir($this->dir(), 0750, true);
        $tmp = $this->statusPath().'.tmp';
        file_put_contents($tmp, json_encode($status + ['updated_at' => now()->toIso8601String()]));
        rename($tmp, $this->statusPath());
    }

    private function progress(string $upload, string $phase, int $percent, array $extra = []): void
    {
        $this->writeStatus(['state' => 'running', 'upload_id' => $upload, 'phase' => $phase, 'percent' => $percent] + $extra);
    }

    public function isRunning(): bool
    {
        return ($this->status()['state'] ?? 'idle') === 'running';
    }

    /** Mark running and start the detached process. The upload must already be complete. */
    public function launch(string $uploadId): void
    {
        $this->progress($uploadId, 'Starting', 1);
        $log = escapeshellarg($this->dir().'/restore.log');
        $php = escapeshellarg(config('backup.php_binary'));
        Process::path(base_path())->run("setsid -f {$php} artisan restore:run {$uploadId} >> {$log} 2>&1 < /dev/null");
    }

    private function lockHeld(): bool
    {
        @mkdir($this->dir(), 0750, true);
        $handle = fopen($this->dir().'/.lock', 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    public function run(string $uploadId): void
    {
        @mkdir($this->dir(), 0750, true);
        $lock = fopen($this->dir().'/.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        $uploads = $this->uploads();
        try {
            $this->perform($uploadId, $uploads);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            Log::error('Restore failed', ['message' => $e->getMessage()]);
            $this->writeStatus(['state' => 'failed', 'upload_id' => $uploadId, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Unexpected error: '.$e->getMessage()]);
        } finally {
            // Terminal either way: free the (possibly huge) upload and work files.
            try {
                $uploads->delete($uploadId);
            } catch (Throwable) {
            }
            $this->removeTree($this->dir().'/'.$uploadId);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function perform(string $id, ChunkedUpload $uploads): void
    {
        $this->progress($id, 'Checking the backup', 2);
        $zipPath = $uploads->partPath($id);
        if (! is_file($zipPath)) {
            throw new RuntimeException('The uploaded file is missing.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('That file is not a valid zip archive (it may be damaged or incomplete).');
        }

        $manifest = $this->readManifest($zip);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        if ($problems = app(BackupCompatibility::class)->problems($manifest, $names)) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $entries = $manifest['entries'];
        $total = array_sum(array_column($entries, 'size'));
        $work = $this->dir()."/{$id}/work";
        @mkdir($work, 0700, true);
        $free = @disk_free_space($work);
        if ($free !== false && $free < $total * 1.05 + 50 * 1024 * 1024) {
            throw new RuntimeException('Not enough free disk space to unpack this backup ('.$this->human($total).' needed, '.$this->human((int) $free).' free).');
        }

        // ---- 2. extract + verify ----
        $done = 0;
        $lastReport = 0.0;
        foreach ($entries as $entry) {
            $dest = "{$work}/{$entry['path']}";
            @mkdir(dirname($dest), 0700, true);
            $in = $zip->getStream($entry['path']);
            $out = fopen($dest, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException("Could not read {$entry['path']} from the backup.");
            }
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($in)) {
                $buffer = fread($in, self::CHUNK);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                hash_update($hash, $buffer);
                fwrite($out, $buffer);
                $size += strlen($buffer);
                $done += strlen($buffer);
                if (microtime(true) - $lastReport > 1.0) {
                    $lastReport = microtime(true);
                    $this->progress($id, 'Unpacking and verifying files', 5 + (int) (55 * $done / max(1, $total)));
                }
            }
            fclose($in);
            fclose($out);
            if ($size !== $entry['size'] || ! hash_equals($entry['sha256'], hash_final($hash))) {
                throw new RuntimeException("The backup is damaged: {$entry['path']} does not match its checksum. Nothing was restored.");
            }
        }
        $zip->close();
        @unlink($zipPath); // the unpacked copy is verified; give the disk back

        // ---- 3. database ----
        $this->progress($id, 'Replacing the database', 62);
        $this->replaceDatabase("{$work}/database/db.sql");
        $this->progress($id, 'Updating the database to this version', 78);
        $this->migrate();

        // ---- 4. files ----
        $this->progress($id, 'Restoring uploaded files', 85);
        $this->moveTree("{$work}/files/private", config('filesystems.disks.local.root'));
        $this->moveTree("{$work}/files/public", config('filesystems.disks.public.root'));

        // ---- 5/6. fix-ups ----
        $this->progress($id, 'Finishing up', 95);
        $blanked = $this->blankUndecryptable();
        $this->clearMachineLocalState();

        $adminMissing = ! app(FirstRunService::class)->administratorExists();
        if (! $adminMissing) {
            app(FirstRunService::class)->markComplete();
        }

        $this->writeStatus([
            'state' => 'complete', 'upload_id' => $id, 'phase' => 'Done', 'percent' => 100,
            'blanked' => $blanked, 'admin_missing' => $adminMissing,
            'includes_uploads' => (bool) ($manifest['includes_uploads'] ?? false),
            'backup_created_at' => $manifest['created_at'] ?? null,
        ]);
    }

    private function readManifest(ZipArchive $zip): array
    {
        $stat = $zip->statName('manifest.json');
        if ($stat === false || $stat['size'] > 64 * 1024 * 1024) {
            throw new RuntimeException('This does not look like a Relief Inventory backup (no manifest.json).');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('The backup manifest is not readable.');
        }

        return $manifest;
    }

    private function replaceDatabase(string $sqlFile): void
    {
        try {
            // The instance has never been set up, so what is in the database is only the fresh schema.
            // Dropping everything (rather than loading over it) means a backup from an older version
            // can't collide with tables that only the current migrations create.
            $this->artisan('db:wipe', ['--force' => true]);

            $tools = app(DatabaseTools::class);
            $input = fopen($sqlFile, 'rb');
            $process = $tools->process($tools->clientCommand());
            $process->setTimeout(null);
            $process->setInput($input);
            $process->run();
            fclose($input);
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Loading the database dump failed: '.substr(trim($process->getErrorOutput()), 0, 400));
            }
        } catch (Throwable $e) {
            $this->recoverFreshState();
            throw new RuntimeException($e->getMessage().' The instance was reset to a clean state; you can try again.');
        }
    }

    private function migrate(): void
    {
        try {
            $this->artisan('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            $this->recoverFreshState();
            throw new RuntimeException('Updating the restored database failed: '.$e->getMessage().' The instance was reset to a clean state; you can try again.');
        }
    }

    /** After a failed load: schema + essential data again, so /install works. Best effort. */
    private function recoverFreshState(): void
    {
        try {
            $this->artisan('db:wipe', ['--force' => true]);
            $this->artisan('migrate', ['--force' => true]);
            $this->artisan('setup:seed-essentials');
        } catch (Throwable $e) {
            Log::critical('Restore could not return the instance to a clean state', ['message' => $e->getMessage()]);
        }
    }

    private function artisan(string $command, array $args = []): void
    {
        $code = Artisan::call($command, $args);
        if ($code !== 0) {
            throw new RuntimeException("{$command} failed: ".trim(Artisan::output()));
        }
    }

    private function moveTree(string $from, ?string $to): void
    {
        if (! is_dir($from) || ! $to) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $dest = rtrim($to, '/').'/'.substr($file->getPathname(), strlen($from) + 1);
            @mkdir(dirname($dest), 0750, true);
            // rename() fails across filesystems (the work dir may be on another volume): copy then delete.
            if (! @rename($file->getPathname(), $dest)) {
                if (! copy($file->getPathname(), $dest)) {
                    throw new RuntimeException('Could not write restored file '.substr($dest, strlen($to)));
                }
                @unlink($file->getPathname());
            }
        }
    }

    /** @return string[] human labels of the settings that had to be blanked */
    private function blankUndecryptable(): array
    {
        $blanked = [];
        foreach (config('backup.encrypted_columns') as $target => $label) {
            [$table, $column] = explode('.', $target, 2);
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $count = 0;
            foreach (DB::table($table)->whereNotNull($column)->get(['id', $column]) as $row) {
                try {
                    Crypt::decryptString($row->{$column});
                } catch (DecryptException) {
                    DB::table($table)->where('id', $row->id)->update([$column => null]);
                    $count++;
                }
            }
            if ($count > 0) {
                $blanked[] = $label;
            }
        }

        return $blanked;
    }

    /** Rows that point at files a backup deliberately never carries. */
    private function clearMachineLocalState(): void
    {
        if (Schema::hasTable('backups')) {
            DB::table('backups')->delete(); // the previous machine's zips are not here
        }
        if (Schema::hasTable('device_releases')) {
            DB::table('device_releases')->delete(); // firmware images are excluded from backups; re-upload them
        }
        if (Schema::hasTable('print_jobs')) {
            DB::table('print_jobs')->whereIn('status', ['queued', 'claimed', 'failed'])->update(['status' => 'cancelled']); // their PDFs aren't here
        }
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function human(int $bytes): string
    {
        return $bytes > 1073741824 ? round($bytes / 1073741824, 1).' GB' : round($bytes / 1048576).' MB';
    }
}
