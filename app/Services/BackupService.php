<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * On-demand backup zips (see scripts/BACKUPS.md, "Backup files").
 *
 * Layout of a zip:
 *   manifest.json            format version, app commit, migrations, and size + SHA-256 of every entry below
 *   database/db.sql          plain mariadb-dump of the app database
 *   files/private/...        storage/app/private (the `local` disk: receiving photos, screenshots, imports, BOLs)
 *   files/public/...         storage/app/public           (only when "include uploads" was chosen)
 *
 * The application key (APP_KEY / .env / storage/app/.app_key) is NEVER written to a zip — see
 * NEVER_INCLUDE and the "Application key" panel on /setup/backups. A restore onto a new instance gets a
 * fresh key and blanks whatever was encrypted with the old one.
 *
 * Running: the web request only creates the row and starts `php artisan backup:run <id>` as a detached
 * process (setsid), so it outlives the request, needs no queue worker, and can't be killed by a worker
 * timeout or re-run by a queue's retry_after. One backup at a time is enforced twice: the row check in
 * start(), and an flock on <dir>/.backup.lock in run() that the kernel drops if the process dies.
 */
class BackupService
{
    public const FORMAT = 1;

    /** File names that must never be archived, wherever they are found. */
    private const NEVER_INCLUDE = ['.app_key', '.env', '.env.backup', '.env.production'];

    /** Already-compressed types: stored as-is instead of burning CPU deflating them again. */
    private const STORE_ONLY = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'pdf', 'zip', 'gz', 'mp4'];

    /** Create the record and start the detached process. Throws if a backup is already running. */
    public function start(?int $personId, bool $includeUploads): Backup
    {
        $this->reapInterrupted();

        $backup = DB::transaction(function () use ($personId, $includeUploads) {
            if (Backup::whereIn('status', Backup::ACTIVE)->lockForUpdate()->exists()) {
                throw new RuntimeException('A backup is already running. Wait for it to finish.');
            }

            return Backup::create([
                'status' => 'queued', 'include_uploads' => $includeUploads, 'requested_by' => $personId,
            ]);
        });

        $this->spawn($backup);

        return $backup;
    }

    private function spawn(Backup $backup): void
    {
        $this->ensureDir();
        $log = escapeshellarg($this->dir().'/backup.log');
        $php = escapeshellarg(config('backup.php_binary'));
        // setsid -f: new session, forked away — survives the web request and the web server's restart of workers.
        Process::path(base_path())->run("setsid -f {$php} artisan backup:run {$backup->id} >> {$log} 2>&1 < /dev/null");
    }

    /** Build the zip for an existing row. Runs in the detached process (or inline from backup:create). */
    public function run(Backup $backup): void
    {
        $this->ensureDir();
        $lock = fopen($this->dir().'/.backup.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->fail($backup, 'Another backup is already running.');

            return;
        }

        $work = $this->dir().'/.work-'.$backup->id;
        try {
            $backup->update(['status' => 'running', 'started_at' => now(), 'progress' => 'Starting']);
            $this->prune();
            @mkdir($work, 0700, true);

            $this->build($backup, $work);
        } catch (Throwable $e) {
            report($e);
            $this->fail($backup, $e->getMessage());
        } finally {
            $this->removeTree($work);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function build(Backup $backup, string $work): void
    {
        $stamp = now()->format('Ymd-His');
        $partial = $this->dir()."/.partial-{$backup->id}.zip";
        $final = $this->dir()."/relief-backup-{$stamp}-{$backup->id}.zip";

        $this->progress($backup, 'Dumping the database');
        $sql = "{$work}/db.sql";
        $this->dumpDatabase($sql);

        $entries = [['path' => 'database/db.sql', 'source' => $sql]];
        if ($backup->include_uploads) {
            $this->progress($backup, 'Listing uploaded files');
            foreach ($this->uploadFiles() as $entry) {
                $entries[] = $entry;
            }
        }

        $zip = new ZipArchive;
        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file (is the backup directory writable and not full?).');
        }

        $manifestEntries = [];
        $total = count($entries);
        foreach ($entries as $i => $entry) {
            if (($i % 50) === 0) {
                $this->progress($backup, "Checksumming files ({$i} of {$total})");
            }
            $size = filesize($entry['source']);
            $manifestEntries[] = ['path' => $entry['path'], 'size' => $size, 'sha256' => hash_file('sha256', $entry['source'])];
            $zip->addFile($entry['source'], $entry['path']);
            if (in_array(strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION)), self::STORE_ONLY, true)) {
                $zip->setCompressionName($entry['path'], ZipArchive::CM_STORE);
            }
        }

        $zip->addFromString('manifest.json', json_encode($this->manifest($backup, $manifestEntries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->progress($backup, 'Compressing');
        if (! $zip->close()) {
            @unlink($partial);
            throw new RuntimeException('Writing the zip failed (disk full?).');
        }

        $this->verify($partial, $total + 1);
        rename($partial, $final);
        @chmod($final, 0640);

        $backup->update([
            'status' => 'complete', 'filename' => basename($final), 'size_bytes' => filesize($final),
            'file_count' => $total, 'progress' => null, 'finished_at' => now(),
            'expires_at' => now()->addDays((int) config('backup.keep_days')),
        ]);
    }

    private function manifest(Backup $backup, array $entries): array
    {
        return [
            'format' => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'app' => ['name' => config('app.name'), 'commit' => app(GitVersionService::class)->currentCommit()],
            'database' => [
                'driver' => config('database.default'),
                'name' => config('database.connections.'.config('database.default').'.database'),
                'dump' => 'database/db.sql',
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
            ],
            'includes_uploads' => $backup->include_uploads,
            'excluded_dirs' => $backup->include_uploads ? config('backup.exclude_dirs') : [],
            'contains_app_key' => false,
            'entries' => $entries,
        ];
    }

    /** @return iterable<array{path: string, source: string}> */
    private function uploadFiles(): iterable
    {
        $roots = ['private' => config('filesystems.disks.local.root'), 'public' => config('filesystems.disks.public.root')];
        $excluded = config('backup.exclude_dirs');

        foreach ($roots as $label => $root) {
            if (! $root || ! is_dir($root)) {
                continue;
            }
            $root = realpath($root);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($root, $excluded) {
                    if ($file->isLink()) {
                        return false;
                    }
                    $relative = substr($file->getPathname(), strlen($root) + 1);

                    return ! in_array($relative, $excluded, true) && ! in_array($file->getFilename(), self::NEVER_INCLUDE, true);
                }
            ));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    yield ['path' => "files/{$label}/".substr($file->getPathname(), strlen($root) + 1), 'source' => $file->getPathname()];
                }
            }
        }
    }

    private function dumpDatabase(string $to): void
    {
        $tools = app(DatabaseTools::class);
        $process = $tools->process($tools->dumpCommand($to));
        $process->run();
        if (! $process->isSuccessful() || ! is_file($to) || filesize($to) === 0) {
            throw new RuntimeException('Database dump failed: '.substr(trim($process->getErrorOutput()), 0, 300));
        }
    }

    /** Re-open the finished zip and confirm it lists every entry we meant to write. */
    private function verify(string $path, int $expected): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true || $zip->numFiles !== $expected) {
            @unlink($path);
            throw new RuntimeException('The finished zip failed its self-check and was discarded.');
        }
        $zip->close();
    }

    private function progress(Backup $backup, string $text): void
    {
        $backup->update(['progress' => $text]);
    }

    private function fail(Backup $backup, string $message): void
    {
        $backup->update(['status' => 'failed', 'error' => substr($message, 0, 1000), 'progress' => null, 'finished_at' => now()]);
        @unlink($this->dir()."/.partial-{$backup->id}.zip");
    }

    /** Active rows whose process is gone (crash, container restart) become failed instead of blocking forever. */
    public function reapInterrupted(): void
    {
        $active = Backup::whereIn('status', Backup::ACTIVE)->get();
        if ($active->isEmpty() || $this->lockHeld()) {
            return;
        }
        foreach ($active as $backup) {
            // A "queued" row may simply not have started yet; give its process a moment.
            if ($backup->status === 'queued' && $backup->created_at->gt(now()->subSeconds((int) config('backup.stale_after_seconds')))) {
                continue;
            }
            $this->fail($backup, 'The backup process stopped before finishing (server restart or crash).');
        }
    }

    private function lockHeld(): bool
    {
        $this->ensureDir();
        $handle = fopen($this->dir().'/.backup.lock', 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    /** Delete expired zips (and old failed rows). Cheap; called lazily and from the scheduler container. */
    public function prune(): int
    {
        $count = 0;
        Backup::where('status', 'complete')->where('expires_at', '<', now())->get()->each(function (Backup $backup) use (&$count) {
            $this->delete($backup);
            $count++;
        });
        Backup::where('status', 'failed')->where('finished_at', '<', now()->subDays(7))->delete();

        return $count;
    }

    public function delete(Backup $backup): void
    {
        if ($backup->filename) {
            @unlink($backup->path());
        }
        $backup->delete();
    }

    public function dir(): string
    {
        return rtrim(config('backup.dir'), '/');
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->dir()) && ! @mkdir($this->dir(), 0750, true) && ! is_dir($this->dir())) {
            throw new RuntimeException("Cannot create the backup directory {$this->dir()}.");
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
}
