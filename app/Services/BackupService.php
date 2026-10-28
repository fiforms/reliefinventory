<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Backup;
use App\Services\Warehouses\WarehouseAccounts;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backup zips (see scripts/BACKUPS.md). The one backup format for every platform: the admin page, `backup:create`,
 * the bare-metal tiered backups (scripts/update.sh calls `backup:create --dest=...`) and the Docker daily and
 * pre-migration dumps all write this.
 *
 * Scope — what a zip holds:
 *   database   the database dump only
 *   full       the database dump and the uploaded files
 *   storage    the uploaded files only (disaster recovery: a good copy of the files to pair with a database
 *              zip — see RestoreService; offered by `backup:create --scope=storage`, not by the admin page)
 *
 * Layout of a zip:
 *   manifest.json            format version, scope, app commit, migrations, and size + SHA-256 of every entry below
 *   database/db.sql          plain mariadb-dump of the app database (scope database, full)
 *   database/accounts.json   this warehouse's logins (WarehouseAccounts::export(); with the dump, since the
 *                            accounts schema isn't in it — MULTI_WAREHOUSE.md §6)
 *   files/private/...        the warehouse's files folder (the `local` disk: receiving photos, screenshots, imports,
 *                            BOLs) (scope full, storage). Older backups may also have files/public/..., ignored.
 *
 * The application key (APP_KEY / .env / storage/app/.app_key) is NEVER written to a zip — see
 * NEVER_INCLUDE and the "Application key" panel on /setup/system. A restore onto a new instance gets a
 * fresh key and blanks whatever was encrypted with the old one.
 *
 * Running: the web request only creates the row and starts `php artisan backup:run <id>` as a detached
 * process (setsid), so it outlives the request, needs no queue worker, and can't be killed by a worker
 * timeout or re-run by a queue's retry_after. One backup at a time is enforced twice: the row check in
 * start(), and an flock on <dir>/.backup.lock in run() that the kernel drops if the process dies. A backup
 * written to some other directory (createIn(): tiered backups, disaster recovery) has no row, no expiry and
 * its own lock — retention is its caller's business, and it works with the database down.
 */
class BackupService
{
    public const FORMAT = 2;

    public const SCOPES = ['database', 'full', 'storage'];

    /** File names that must never be archived, wherever they are found. */
    private const NEVER_INCLUDE = ['.app_key', '.env', '.env.backup', '.env.production'];

    /** Already-compressed types: stored as-is instead of burning CPU deflating them again. */
    private const STORE_ONLY = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'pdf', 'zip', 'gz', 'mp4'];

    public static function hasDatabase(string $scope): bool
    {
        return $scope !== 'storage';
    }

    public static function hasStorage(string $scope): bool
    {
        return $scope !== 'database';
    }

    /** Create the record and start the detached process. Throws if a backup is already running. */
    public function start(?int $personId, string $scope = 'database'): Backup
    {
        $this->reapInterrupted();

        $backup = DB::transaction(function () use ($personId, $scope) {
            if (Backup::whereIn('status', Backup::ACTIVE)->lockForUpdate()->exists()) {
                throw new RuntimeException('A backup is already running. Wait for it to finish.');
            }

            return Backup::create(['status' => 'queued', 'scope' => $scope, 'requested_by' => $personId]);
        });

        $this->spawn($backup);

        return $backup;
    }

    private function spawn(Backup $backup): void
    {
        $this->ensureDir($this->dir());
        $log = escapeshellarg($this->dir().'/backup.log');
        $php = escapeshellarg(config('backup.php_binary'));
        // setsid -f: new session, forked away — survives the web request and the web server's restart of workers.
        Process::path(base_path())->run("setsid -f {$php} artisan backup:run {$backup->id} >> {$log} 2>&1 < /dev/null");
    }

    /** Build the zip for an existing row. Runs in the detached process (or inline from backup:create). */
    public function run(Backup $backup): void
    {
        $this->ensureDir($this->dir());
        $lock = $this->lock($this->dir());
        if (! $lock) {
            $this->fail($backup, 'Another backup is already running.');

            return;
        }

        $instance = null;
        try {
            $backup->update(['progress' => 'Waiting for another backup to finish']);
            $instance = $this->instanceLock();
            if (! $instance) {
                throw new RuntimeException('Another warehouse\'s backup kept the instance busy for too long. Try again later.');
            }
            $backup->update(['status' => 'running', 'started_at' => now(), 'progress' => 'Starting']);
            $this->prune();

            $base = sprintf('relief-backup-%s-%s-%d', now()->format('Ymd-His'), $backup->scope, $backup->id);
            $result = $this->write($backup->scope, $this->dir(), $base, fn (string $text) => $backup->update(['progress' => $text]));

            $backup->update([
                'status' => 'complete', 'filename' => basename($result['path']), 'size_bytes' => $result['size'],
                'file_count' => $result['files'], 'progress' => null, 'finished_at' => now(),
                'expires_at' => now()->addDays((int) config('backup.keep_days')),
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->fail($backup, $e->getMessage());
        } finally {
            $instance && $this->unlock($instance);
            $this->unlock($lock);
        }
    }

    /**
     * Write a zip into $dir with no database row (so it works with the database down) and no expiry — whoever asks
     * for it owns its retention. $name is the file name without ".zip" (default: relief-backup-<stamp>-<scope>).
     *
     * @param  ?callable(string): void  $progress
     * @return array{path: string, size: int, files: int}
     */
    public function createIn(string $dir, string $scope, ?string $name = null, ?callable $progress = null): array
    {
        $dir = rtrim($dir, '/');
        $this->ensureDir($dir);
        $lock = $this->lock($dir);
        if (! $lock) {
            throw new RuntimeException("Another backup is already running in {$dir}.");
        }
        $instance = null;
        try {
            $instance = $this->instanceLock() ?? throw new RuntimeException('Another backup kept the instance busy for too long. Try again later.');
            $base = $name ?? sprintf('relief-backup-%s-%s', now()->format('Ymd-His'), $scope);

            return $this->write($scope, $dir, $base, $progress ?? fn () => null);
        } finally {
            $instance && $this->unlock($instance);
            $this->unlock($lock);
        }
    }

    /**
     * The one place a zip is built: dump and/or list files, checksum, zip, self-check, move into place.
     *
     * @param  callable(string): void  $progress
     * @return array{path: string, size: int, files: int}
     */
    private function write(string $scope, string $dir, string $base, callable $progress): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new RuntimeException("Unknown backup scope '{$scope}'.");
        }
        $work = "{$dir}/.work-".bin2hex(random_bytes(4));
        $partial = "{$dir}/.partial-{$base}.zip";
        $final = "{$dir}/{$base}.zip";
        if (is_file($final)) {
            throw new RuntimeException("{$base}.zip already exists in {$dir}.");
        }

        try {
            @mkdir($work, 0700, true);

            $entries = [];
            if (self::hasDatabase($scope)) {
                $progress('Dumping the database');
                $this->dumpDatabase("{$work}/db.sql");
                $entries[] = ['path' => 'database/db.sql', 'source' => "{$work}/db.sql"];
                // A database not yet moved to central accounts (the pre-migration backup of an upgrade, taken before
                // the accounts schema exists) still holds the logins in its own people table: nothing to add.
                if (! Schema::hasColumn('people', 'password')) {
                    file_put_contents("{$work}/accounts.json", json_encode(app(WarehouseAccounts::class)->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $entries[] = ['path' => 'database/accounts.json', 'source' => "{$work}/accounts.json"];
                }
            }
            if (self::hasStorage($scope)) {
                $progress('Listing uploaded files');
                foreach ($this->storageFiles() as $entry) {
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
                    $progress("Checksumming files ({$i} of {$total})");
                }
                $manifestEntries[] = ['path' => $entry['path'], 'size' => filesize($entry['source']), 'sha256' => hash_file('sha256', $entry['source'])];
                $zip->addFile($entry['source'], $entry['path']);
                if (in_array(strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION)), self::STORE_ONLY, true)) {
                    $zip->setCompressionName($entry['path'], ZipArchive::CM_STORE);
                }
            }

            $zip->addFromString('manifest.json', json_encode($this->manifest($scope, $manifestEntries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $progress('Compressing');
            if (! $zip->close()) {
                @unlink($partial);
                throw new RuntimeException('Writing the zip failed (disk full?).');
            }

            $this->verify($partial, $total + 1);
            rename($partial, $final);
            @chmod($final, 0640);

            return ['path' => $final, 'size' => filesize($final), 'files' => $total];
        } catch (Throwable $e) {
            @unlink($partial);
            throw $e;
        } finally {
            $this->removeTree($work);
        }
    }

    private function manifest(string $scope, array $entries): array
    {
        return [
            'format' => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'app' => ['name' => config('app.name'), 'commit' => app(GitVersionService::class)->currentCommit(), 'version' => app(GitVersionService::class)->release()],
            'scope' => $scope,
            'database' => [
                'driver' => config('database.default'),
                'name' => config('database.connections.'.config('database.default').'.database'),
                'dump' => self::hasDatabase($scope) ? 'database/db.sql' : null,
                'migrations' => $this->migrations($scope),
            ],
            'includes_database' => self::hasDatabase($scope),
            'includes_uploads' => self::hasStorage($scope),
            'excluded_dirs' => self::hasStorage($scope) ? config('backup.exclude_dirs') : [],
            'contains_app_key' => false,
            'entries' => $entries,
        ];
    }

    /** A files-only backup is for when the database may be unreachable, so it records the migration list if it can, else null. */
    private function migrations(string $scope): ?array
    {
        try {
            return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        } catch (Throwable $e) {
            if (self::hasDatabase($scope)) {
                throw $e;
            }

            return null;
        }
    }

    /** @return iterable<array{path: string, source: string}> */
    private function storageFiles(): iterable
    {
        // The current warehouse's files folder (MULTI_WAREHOUSE.md §11). There is no `public` disk any more;
        // older backups' files/public entries are accepted and ignored on restore.
        $roots = ['private' => config('filesystems.disks.local.root')];
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
        if (! $tools->dumpIsComplete($to)) {
            throw new RuntimeException('The database dump ended early (no end-of-dump marker), so it was discarded.');
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
        $this->ensureDir($this->dir());
        $handle = fopen($this->dir().'/.backup.lock', 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    /**
     * The one lock every backup on the instance takes (MULTI_WAREHOUSE.md §10): N warehouses' backups run one after another, not all at
     * once on the one database. Waits for it, up to config('backup.lock_wait_seconds').
     *
     * @return resource|null
     */
    private function instanceLock()
    {
        $this->ensureDir($this->rootDir());
        $handle = fopen($this->rootDir().'/.instance-backup.lock', 'c');
        $deadline = time() + (int) config('backup.lock_wait_seconds');
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);

                return null;
            }
            sleep(2);
        }

        return $handle;
    }

    /** @return resource|null the held lock, or null when another backup in $dir holds it */
    private function lock(string $dir)
    {
        $handle = fopen($dir.'/.backup.lock', 'c');
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
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

        return $count + $this->pruneUntracked();
    }

    /**
     * Zips in the backup directory with no row, older than the retention: the pre-migration backups (written before
     * the `backups` table can be relied on) and anything left over after a restore replaced the table. Only
     * files this class names are touched — relief-backup-*.zip — never anything else an operator keeps there.
     */
    private function pruneUntracked(): int
    {
        $cutoff = now()->subDays((int) config('backup.keep_days'))->getTimestamp();
        $known = Backup::whereNotNull('filename')->pluck('filename')->flip();
        $count = 0;
        foreach (glob($this->dir().'/relief-backup-*.zip') ?: [] as $path) {
            if (! isset($known[basename($path)]) && filemtime($path) < $cutoff && @unlink($path)) {
                $count++;
            }
        }

        return $count;
    }

    public function delete(Backup $backup): void
    {
        if ($backup->filename) {
            @unlink($backup->path());
        }
        $backup->delete();
    }

    /** This warehouse's own backup folder: its zips, its backup.log, its lock (MULTI_WAREHOUSE.md §11). */
    public function dir(): string
    {
        return $this->rootDir().'/warehouses/'.app(WarehouseContext::class)->current()->id;
    }

    /** The instance's backup directory, above every warehouse's own folder. */
    public function rootDir(): string
    {
        return rtrim(config('backup.dir'), '/');
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create the backup directory {$dir}.");
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
