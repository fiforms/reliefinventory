<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Accounts\Account;
use App\Models\Control\SetupCode;
use App\Services\Warehouses\WarehouseAccounts;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseSetup;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restore backup zips (BackupService's format). Two entry points share one pipeline: the first-run wizard's
 * detached `restore:run` (uploads onto a brand-new instance — the only restore the UI offers) and the
 * `backup:restore` command (zips on disk; an instance that has already been set up needs --force).
 *
 * One or two zips. The database comes from the first (any zip with a database: scope database or full); the files
 * come from the second when one is given (scope storage or full), else from the first when it has them. So a
 * database corruption is recoverable from the latest good database zip plus the best copy of the files — and if
 * that second zip also holds a database, the database part is ignored, as is the first zip's files when a second
 * zip is supplied. A files-only restore (no database zip) leaves the database untouched. Files are laid over
 * what is already there; nothing is deleted.
 *
 * Phases, in the order that keeps a bad file harmless:
 *   1. read + validate every manifest (BackupCompatibility), choose the sources, check free disk space
 *   2. extract the entries actually needed to a work directory, verifying size and SHA-256 as it streams
 *      — nothing on the instance has changed up to here, so any failure leaves it untouched
 *   3. wipe the database, load database/db.sql, run migrations
 *   4. move uploaded files into place
 *   5. blank values encrypted with a key this instance doesn't have (config backup.encrypted_columns)
 *   6. clear machine-local state that points at files a backup never carries, set the setup-complete latch
 *
 * The database is replaced in step 3, so progress can't live in it: it lives in <backup dir>/restore/status.json
 * and the wizard polls that. Like BackupService this runs as a detached process (restore:run), one at a time
 * via an flock. If step 3 fails on a never-set-up instance, it is put back to a clean migrated + seeded state so
 * the wizard is usable again. On an instance that WAS set up (--force) that would be wrong — a wiped database
 * has no setup-complete latch, so the setup wizard would be open to anyone — so the site is put into maintenance
 * mode before the wipe, and left there if the load fails, for the operator to retry or reset.
 */
class RestoreService
{
    private const CHUNK = 1024 * 1024;

    /**
     * This warehouse's restore folder: uploads, work files, status and lock. One per warehouse (MULTI_WAREHOUSE.md §11), so
     * two warehouses can restore at once and one's progress is never shown to another's browser.
     */
    public function dir(): string
    {
        return rtrim(config('backup.dir'), '/').'/warehouses/'.app(WarehouseContext::class)->current()->id.'/restore';
    }

    /** True while a restore is setting up a new warehouse for the holder of its setup code (see stageClaim()). */
    private bool $provisioned = false;

    private function claimPath(): string
    {
        return $this->dir().'/claim.json';
    }

    /**
     * Who a restore into a warehouse awaiting setup is for: the login (already proven by its password), the person's name,
     * and the setup code row. It travels in a file, not the session, because replacing the database wipes the sessions
     * table. Its presence is what makes a restore "provisioned": the backup is untrusted (its accounts become invitations,
     * MULTI_WAREHOUSE.md §6), no instance-wide maintenance mode is used, a failure returns the warehouse to its empty
     * ready-to-set-up state, and when the data is in the holder becomes Administrator and the warehouse goes active.
     *
     * @param  array{code_id:int,account_id:int,first_name:string,last_name:string}  $claim
     */
    public function stageClaim(array $claim): void
    {
        @mkdir($this->dir(), 0750, true);
        file_put_contents($this->claimPath(), json_encode($claim), LOCK_EX);
        chmod($this->claimPath(), 0600);
    }

    public function clearClaim(): void
    {
        @unlink($this->claimPath());
    }

    private function claim(): ?array
    {
        $claim = is_file($this->claimPath()) ? json_decode((string) file_get_contents($this->claimPath()), true) : null;

        return is_array($claim) && isset($claim['code_id'], $claim['account_id'], $claim['first_name'], $claim['last_name']) ? $claim : null;
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

    /** Mark running and start the detached process. The upload(s) must already be complete. */
    public function launch(string $uploadId, ?string $storageUploadId = null): void
    {
        $this->progress($uploadId, 'Starting', 1);
        $log = escapeshellarg($this->dir().'/restore.log');
        $php = escapeshellarg(config('backup.php_binary'));
        $args = escapeshellarg($uploadId).($storageUploadId ? ' '.escapeshellarg($storageUploadId) : '');
        $warehouse = escapeshellarg('--warehouse='.app(WarehouseContext::class)->current()->id);
        Process::path(base_path())->run("setsid -f {$php} artisan restore:run {$args} {$warehouse} >> {$log} 2>&1 < /dev/null");
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

    /** The wizard's path: uploaded zips, a never-set-up instance. */
    public function run(string $uploadId, ?string $storageUploadId = null): void
    {
        @mkdir($this->dir(), 0750, true);
        $lock = fopen($this->dir().'/.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        $uploads = $this->uploads();
        try {
            $this->perform($uploadId, $uploads->partPath($uploadId), $storageUploadId ? $uploads->partPath($storageUploadId) : null, false, true);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            Log::error('Restore failed', ['message' => $e->getMessage()]);
            $this->writeStatus(['state' => 'failed', 'upload_id' => $uploadId, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Unexpected error: '.$e->getMessage()]);
        } finally {
            // Terminal either way: free the (possibly huge) upload and work files.
            foreach (array_filter([$uploadId, $storageUploadId]) as $id) {
                try {
                    $uploads->delete($id);
                } catch (Throwable) {
                }
            }
            $this->removeTree($this->dir().'/'.$uploadId);
            $this->clearClaim();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The command's path: zips already on disk (never deleted). Throws RuntimeException with the reason on failure.
     *
     * @param  ?string  $databaseZip  supplies the database (and the files too, unless $storageZip is given)
     * @param  ?string  $storageZip  supplies the files; any database inside it is ignored
     * @param  bool  $force  allow restoring onto an instance that has already been set up
     */
    public function runFromFiles(?string $databaseZip, ?string $storageZip, bool $force): array
    {
        @mkdir($this->dir(), 0750, true);
        $lock = fopen($this->dir().'/.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another restore is already running.');
        }

        $id = 'cli-'.bin2hex(random_bytes(4));
        try {
            return $this->perform($id, $databaseZip, $storageZip, $force, false);
        } catch (Throwable $e) {
            $this->writeStatus(['state' => 'failed', 'upload_id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $this->removeTree($this->dir().'/'.$id);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  bool  $force  the instance may already be set up (see class doc)
     * @param  bool  $consume  the zips are throwaway uploads: free each one's disk as soon as it is unpacked
     */
    private function perform(string $id, ?string $databaseZip, ?string $storageZip, bool $force, bool $consume): array
    {
        $this->progress($id, 'Checking the backup', 2);
        $plan = $this->plan($databaseZip, $storageZip);

        $claim = $this->claim();
        $this->provisioned = $claim !== null;
        if ($claim && ! $plan['database']) {
            throw new RuntimeException('Setting up a new warehouse needs a backup that has a database. This one holds only files.');
        }

        // A warehouse waiting for its setup code is as empty and as safe to replace as a never-set-up instance.
        $fresh = $this->provisioned || rescue(fn () => app(FirstRunService::class)->needsSetup(), false, false);
        if (! $fresh && ! $force) {
            throw new RuntimeException('This instance has already been set up. Restoring replaces its data; add --force if that is what you want.');
        }
        $maintenance = $force && ! $fresh && $plan['database'];

        // ---- 2. extract + verify ----
        $total = array_sum(array_column($plan['sources'], 'bytes'));
        $work = $this->dir()."/{$id}/work";
        @mkdir($work, 0700, true);
        $free = @disk_free_space($work);
        if ($free !== false && $free < $total * 1.05 + 50 * 1024 * 1024) {
            throw new RuntimeException('Not enough free disk space to unpack this backup ('.$this->human($total).' needed, '.$this->human((int) $free).' free).');
        }
        $done = 0;
        foreach ($plan['sources'] as $source) {
            $this->extract($id, $source, $work, $done, $total);
            $source['zip']->close();
            if ($consume) {
                @unlink($source['path']); // the unpacked copy is verified; give the disk back
            }
        }

        // ---- 3. database ----
        if ($plan['database']) {
            // Before anything is wiped: a dump cut off part-way would leave a half-empty database. (Its checksum
            // can't tell: a truncated dump checksums fine.)
            if (! app(DatabaseTools::class)->dumpIsComplete("{$work}/database/db.sql")) {
                throw new RuntimeException('The database dump in this backup is incomplete (it has no end-of-dump marker), so nothing was changed. Use an earlier backup.');
            }
            if ($maintenance) {
                $this->artisan('down', ['--retry' => 60]);
            }
            $this->progress($id, 'Replacing the database', 62);
            $this->replaceDatabase("{$work}/database/db.sql", $fresh);
            // The restored people aren't the ones this warehouse's memberships point at (and an older dump's
            // migration links its own logins, MULTI_WAREHOUSE.md §6).
            app(WarehouseAccounts::class)->forgetMemberships();
            $this->progress($id, 'Updating the database to this version', 78);
            $this->migrate($fresh);
            $accountsFile = "{$work}/database/accounts.json";
            $logins = app(WarehouseAccounts::class)->relinkAfterRestore(
                is_file($accountsFile) ? json_decode((string) file_get_contents($accountsFile), true) : null,
                untrusted: $this->provisioned,
            );
            if ($claim) {
                $logins[] = $this->finishClaim($claim);
            }
        }

        // ---- 4. files ----
        if ($plan['files']) {
            $this->progress($id, 'Restoring uploaded files', 85);
            $this->moveTree("{$work}/files/private", config('filesystems.disks.local.root'));
        }

        // ---- 5/6. fix-ups (they read the database, so only when it is the restored one) ----
        $this->progress($id, 'Finishing up', 95);
        $blanked = [];
        $adminMissing = false;
        if ($plan['database']) {
            $blanked = $this->blankUndecryptable();
            $this->clearMachineLocalState();
            $adminMissing = ! app(FirstRunService::class)->administratorExists();
            if (! $adminMissing) {
                app(FirstRunService::class)->markComplete();
            }
            if ($maintenance) {
                $this->artisan('up');
            }
        }

        $result = [
            'state' => 'complete', 'upload_id' => $id, 'phase' => 'Done', 'percent' => 100,
            'blanked' => $blanked, 'admin_missing' => $adminMissing,
            'database_restored' => $plan['database'], 'includes_uploads' => $plan['files'],
            'backup_created_at' => $plan['database_created_at'], 'files_created_at' => $plan['files_created_at'],
            'notes' => $plan['notes'], 'logins' => $logins ?? [],
        ];
        $this->writeStatus($result);

        return $result;
    }

    /**
     * Open and validate each zip, then decide what is taken from where (see class doc). `sources` are the zips that
     * contribute something, each with a `want` rule saying which of its entries to unpack.
     *
     * @return array{sources: array[], database: bool, files: bool, notes: string[], database_created_at: ?string, files_created_at: ?string}
     */
    private function plan(?string $databaseZip, ?string $storageZip): array
    {
        if ($databaseZip === null && $storageZip === null) {
            throw new RuntimeException('There is nothing to restore: no backup file was given.');
        }
        $db = $databaseZip !== null ? $this->open($databaseZip) : null;
        $files = $storageZip !== null ? $this->open($storageZip) : null;

        if ($db !== null && ! BackupService::hasDatabase($db['scope'])) {
            throw new RuntimeException('That backup holds only files, no database. Give it as the files backup, with a backup that has the database as the main one.');
        }
        if ($files !== null && ! BackupService::hasStorage($files['scope'])) {
            throw new RuntimeException('The files backup holds only a database, no files.');
        }

        // Only the zip whose database is used has to carry a schema this software understands.
        $compat = app(BackupCompatibility::class);
        foreach ([[$db, true], [$files, false]] as [$source, $usesDatabase]) {
            if ($source !== null && $problems = $compat->problems($source['manifest'], $source['names'], null, $usesDatabase)) {
                throw new RuntimeException(implode(' ', $problems));
            }
        }

        $notes = [];
        if ($files !== null && BackupService::hasDatabase($files['scope'])) {
            $notes[] = 'The files backup also contained a database; it was ignored.';
        }
        // A full backup on its own supplies its files too; given a separate files backup, those win.
        $dbSuppliesFiles = $db !== null && $files === null && BackupService::hasStorage($db['scope']);
        if ($db !== null && $files !== null && BackupService::hasStorage($db['scope'])) {
            $notes[] = "The main backup's files were ignored in favour of the separate files backup.";
        }

        $isFile = fn (string $path) => str_starts_with($path, 'files/');
        if ($db !== null) {
            $db['want'] = fn (string $path) => in_array($path, ['database/db.sql', 'database/accounts.json'], true) || ($dbSuppliesFiles && $isFile($path));
        }
        if ($files !== null) {
            $files['want'] = $isFile;
        }
        $sources = array_values(array_filter([$db, $files]));
        foreach ($sources as &$source) {
            $source['bytes'] = array_sum(array_map(fn ($e) => $source['want']($e['path']) ? $e['size'] : 0, $source['manifest']['entries']));
        }
        unset($source);

        return [
            'sources' => $sources, 'database' => $db !== null, 'files' => $files !== null || $dbSuppliesFiles, 'notes' => $notes,
            'database_created_at' => $db['manifest']['created_at'] ?? null,
            'files_created_at' => ($files ?? ($dbSuppliesFiles ? $db : null))['manifest']['created_at'] ?? null,
        ];
    }

    /** @return array{path: string, zip: ZipArchive, manifest: array, names: string[], scope: string} */
    private function open(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(basename($path).' was not found.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException(basename($path).' is not a valid zip archive (it may be damaged or incomplete).');
        }
        $manifest = $this->readManifest($zip);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        return [
            'path' => $path, 'zip' => $zip, 'manifest' => $manifest, 'names' => $names,
            'scope' => app(BackupCompatibility::class)->scope($manifest) ?? 'database',
        ];
    }

    /** Unpack the entries this source is wanted for into $work, checking size and SHA-256 as they stream. */
    private function extract(string $id, array $source, string $work, int &$done, int $total): void
    {
        $lastReport = 0.0;
        foreach ($source['manifest']['entries'] as $entry) {
            if (! $source['want']($entry['path'])) {
                continue;
            }
            $dest = "{$work}/{$entry['path']}";
            @mkdir(dirname($dest), 0700, true);
            $in = $source['zip']->getStream($entry['path']);
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

    /** @param  bool  $fresh  never set up: a failure resets it to a clean state; otherwise it is left for the operator */
    private function replaceDatabase(string $sqlFile, bool $fresh): void
    {
        try {
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
            throw new RuntimeException($e->getMessage().' '.$this->afterDatabaseFailure($fresh));
        }
    }

    private function migrate(bool $fresh): void
    {
        try {
            $this->artisan('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            throw new RuntimeException('Updating the restored database failed: '.$e->getMessage().' '.$this->afterDatabaseFailure($fresh));
        }
    }

    /** The code holder joins the restored warehouse as its Administrator, the code is used up and it goes active. @return string a report line */
    private function finishClaim(array $claim): string
    {
        $warehouse = app(WarehouseContext::class)->current();
        $code = SetupCode::usable()->where('warehouse_id', $warehouse->id)->whereKey($claim['code_id'])->first();
        $account = Account::find($claim['account_id']);
        if (! $code || ! $account) {
            throw new RuntimeException('The setup code was revoked or expired while the backup was loading, so no one was made Administrator. The data is in place: ask for a new setup code.');
        }
        app(WarehouseSetup::class)->withExistingAccount($warehouse, $code, $account, $claim, restored: true);

        return "{$account->email}: Administrator of this warehouse";
    }

    private function afterDatabaseFailure(bool $fresh): string
    {
        if ($this->provisioned) {
            $this->recoverProvisionedState();

            return 'The warehouse was returned to its empty, ready-to-set-up state; your setup code still works.';
        }
        if ($fresh) {
            $this->recoverFreshState();

            return 'The instance was reset to a clean state; you can try again.';
        }

        return 'The database may be empty or incomplete and the site has been left in maintenance mode on purpose '
            .'(a database without its setup record would open the setup wizard to anyone). Run the restore again from a good backup, '
            .'then "php artisan up". If the database cannot be loaded at all, "php artisan setup:reset" erases it and starts clean (it asks for typed confirmation).';
    }

    /**
     * Drop everything and rebuild: schema plus the essential lookup data, no user accounts (only the built-in Unknown Donor placeholder), no setup record — a brand-new
     * instance. Throws on failure. The one caller that may run it on a live instance is `setup:reset`, behind its
     * typed confirmation.
     */
    public function resetInstance(): void
    {
        $this->artisan('db:wipe', ['--force' => true]);
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('setup:seed-essentials');
        app(WarehouseAccounts::class)->forgetAll();
    }

    /**
     * After a failed load into a warehouse awaiting setup: empty schema, essentials, no one in it, and the setup latch set so
     * /install never opens inside it. Touches nothing outside this warehouse (resetInstance() would delete logins
     * that belong to no warehouse, instance admins included). Best effort.
     */
    private function recoverProvisionedState(): void
    {
        try {
            $this->artisan('db:wipe', ['--force' => true]);
            $this->artisan('migrate', ['--force' => true]);
            $firstRun = app(FirstRunService::class);
            $firstRun->ensureEssentialData();
            app(PermissionsSeeder::class)->setContainer(app())->__invoke();
            app(WarehouseAccounts::class)->forgetMemberships();
            $firstRun->markComplete();
        } catch (Throwable $e) {
            Log::critical('Restore could not return the warehouse to its ready-to-set-up state', ['message' => $e->getMessage()]);
        }
    }

    /** After a failed load on a never-set-up instance: back to a clean state so /install works. Best effort. */
    private function recoverFreshState(): void
    {
        try {
            $this->resetInstance();
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
                    Model::currentEncrypter()->decryptString($row->{$column});
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

    /**
     * Rows that point at files a backup deliberately never carries — dropped only where the file really is missing,
     * so restoring onto the machine that made the backups keeps its own zips and firmware listed.
     */
    private function clearMachineLocalState(): void
    {
        if (Schema::hasTable('backups')) {
            $dir = rtrim(config('backup.dir'), '/');
            foreach (DB::table('backups')->get(['id', 'status', 'filename']) as $row) {
                if ($row->status !== 'complete' || ! $row->filename || ! is_file("{$dir}/{$row->filename}")) {
                    DB::table('backups')->where('id', $row->id)->delete();
                }
            }
        }
        if (Schema::hasTable('device_releases')) {
            $root = rtrim((string) config('filesystems.disks.local.root'), '/');
            foreach (DB::table('device_releases')->get(['id', 'storage_path']) as $row) {
                if (! file_exists("{$root}/{$row->storage_path}")) {
                    DB::table('device_releases')->where('id', $row->id)->delete(); // firmware images are excluded from backups; re-upload them
                }
            }
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
