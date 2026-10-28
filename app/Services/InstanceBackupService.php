<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\Provisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * The whole-instance backup and restore (MULTI_WAREHOUSE.md §11): for moving an instance to a new machine and for disaster
 * recovery, nothing finer. One zip holds
 *
 *   instance/dump.sql          one `mariadb-dump --single-transaction --databases` of the control schema, the accounts schema and every
 *                              warehouse's schema, so all of them are from the same instant (live-state tables' rows left out)
 *   instance/instance.json     what is in it: the schema names, the warehouses (id, uuid, schema, name, status)
 *   files/<warehouse id>/...   each warehouse's files folder (optional; the excluded directories are left out as in a warehouse backup)
 *   keys/<warehouse id>/warehouse.key   each warehouse's encryption key, AES-256 encrypted with a passphrase (optional; without a
 *                              passphrase no key is written, and the manifest says so)
 *   manifest.json              `kind: instance`, the entries and their SHA-256s
 *
 * Both halves need the database administrator's password, so they run only in the provisioner container (the one process that has it),
 * and a restore loads SQL as that administrator: it is the operator's act, with the operator's own backup, so unlike a warehouse
 * administrator's restore nothing here is untrusted and nothing is relinked. The dump is not parsed.
 *
 * A restore is onto a new instance (an existing warehouse that has been set up, or a second warehouse, needs --force): it drops the
 * instance's schemas and warehouses, loads the dump, puts the files and keys back and makes each warehouse's database user again
 * (a backup never carries those: the passwords are new). The other containers should be stopped meanwhile.
 */
class InstanceBackupService
{
    public const KIND = 'instance';

    private const CHUNK = 1024 * 1024;

    /** Tables whose rows are left out of the dump, wherever they exist: live state, and the provisioner's own queue. */
    private function ignoredTables(): array
    {
        return [...config('backup.live_state_tables'), 'failed_jobs', 'provisioning_commands'];
    }

    public function dir(): string
    {
        return app(BackupService::class)->rootDir().'/instance';
    }

    /** The backup an upgrade takes before migrating (asked of the provisioner by the web container; fixed arguments, no keys). @return list<string> */
    public function premigrate(): array
    {
        $result = $this->create($this->dir(), 'premigrate', false, null);
        $this->prunePremigrate();

        return ["instance backup written: {$result['path']}"];
    }

    private function prunePremigrate(): void
    {
        $cutoff = now()->subDays((int) config('backup.keep_days'))->getTimestamp();
        foreach (glob($this->dir().'/relief-instance-*-premigrate.zip') ?: [] as $path) {
            if (filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    // ---- backup ------------------------------------------------------------------------------------------------

    /**
     * @param  ?string  $label  appended to the file name
     * @param  bool  $withFiles  include every warehouse's files folder
     * @param  ?string  $passphrase  include the warehouse keys, encrypted with it (none: no keys)
     * @return array{path: string, size: int, schemas: list<string>, warehouses: int}
     */
    public function create(string $dest, ?string $label, bool $withFiles, ?string $passphrase, ?callable $progress = null): array
    {
        $progress ??= fn (string $m) => null;

        return app(BackupService::class)->withInstanceLock(fn () => $this->write($dest, $label, $withFiles, $passphrase, $progress));
    }

    private function write(string $dest, ?string $label, bool $withFiles, ?string $passphrase, callable $progress): array
    {
        $admin = Provisioner::fromConfig()->pdo();
        File::ensureDirectoryExists($dest, 0750);
        $base = 'relief-instance-'.now()->format('Ymd-His').($withFiles ? '-full' : '-database').($label ? "-{$label}" : '');
        $final = "{$dest}/{$base}.zip";
        $partial = "{$dest}/.partial-{$base}.zip";
        $work = "{$dest}/.work-".bin2hex(random_bytes(4));
        if (is_file($final)) {
            throw new RuntimeException("{$base}.zip already exists in {$dest}.");
        }

        try {
            File::ensureDirectoryExists($work, 0700);
            $warehouses = Warehouse::orderBy('id')->get();
            $schemas = [config('database.connections.control.database'), config('database.connections.accounts.database')];
            foreach ($warehouses as $warehouse) {
                if ($this->schemaExists($admin, $warehouse->schema_name)) {
                    $schemas[] = $warehouse->schema_name;
                }
            }

            $progress('Dumping '.count($schemas).' schemas');
            $this->dump($admin, $schemas, "{$work}/dump.sql");
            $info = [
                'control_schema' => $schemas[0], 'accounts_schema' => $schemas[1],
                'warehouses' => $warehouses->map(fn (Warehouse $w) => [
                    'id' => $w->id, 'uuid' => $w->uuid, 'schema' => $w->schema_name, 'name' => $w->display_name, 'status' => $w->status,
                    'default' => (bool) $w->is_default, 'dumped' => in_array($w->schema_name, $schemas, true),
                ])->all(),
            ];
            file_put_contents("{$work}/instance.json", json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $entries = [
                ['path' => 'instance/dump.sql', 'source' => "{$work}/dump.sql"],
                ['path' => 'instance/instance.json', 'source' => "{$work}/instance.json"],
            ];
            $encrypted = [];
            if ($withFiles) {
                $progress('Listing uploaded files');
                foreach ($warehouses as $warehouse) {
                    foreach (app(BackupService::class)->filesIn($warehouse->storagePath(), "files/{$warehouse->id}") as $entry) {
                        $entries[] = $entry;
                    }
                }
            }
            if ($passphrase !== null && $passphrase !== '') {
                foreach ($warehouses as $warehouse) {
                    if ($warehouse->hasKey()) {
                        $entries[] = $entry = ['path' => "keys/{$warehouse->id}/warehouse.key", 'source' => $warehouse->secretsPath('warehouse.key')];
                        $encrypted[] = $entry['path'];
                    }
                }
            }

            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the zip file (is the backup directory writable and not full?).');
            }
            $manifestEntries = [];
            foreach ($entries as $i => $entry) {
                if ($i % 50 === 0) {
                    $progress('Checksumming files ('.$i.' of '.count($entries).')');
                }
                $manifestEntries[] = ['path' => $entry['path'], 'size' => filesize($entry['source']), 'sha256' => hash_file('sha256', $entry['source'])];
                $zip->addFile($entry['source'], $entry['path']);
                if (in_array($entry['path'], $encrypted, true)) {
                    $zip->setEncryptionName($entry['path'], ZipArchive::EM_AES_256, $passphrase);
                }
            }
            $zip->addFromString('manifest.json', json_encode([
                'format' => BackupService::FORMAT,
                'kind' => self::KIND,
                'created_at' => now()->toIso8601String(),
                'app' => ['name' => config('app.name'), 'commit' => app(GitVersionService::class)->currentCommit(), 'version' => app(GitVersionService::class)->release()],
                'schemas' => $schemas,
                'includes_files' => $withFiles,
                'includes_keys' => $encrypted !== [],
                'contains_app_key' => false,
                'contains_warehouse_keys' => $encrypted !== [],
                'entries' => $manifestEntries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $progress('Compressing');
            if (! $zip->close()) {
                throw new RuntimeException('Writing the zip failed (disk full?).');
            }
            $check = new ZipArchive;
            if ($check->open($partial, ZipArchive::CHECKCONS) !== true || $check->numFiles !== count($entries) + 1) {
                throw new RuntimeException('The finished zip failed its self-check and was discarded.');
            }
            $check->close();
            rename($partial, $final);
            @chmod($final, 0600);   // it can hold every warehouse's key

            return ['path' => $final, 'size' => filesize($final), 'schemas' => $schemas, 'warehouses' => $warehouses->count()];
        } finally {
            @unlink($partial);
            File::deleteDirectory($work);
        }
    }

    private function dump(PDO $admin, array $schemas, string $to): void
    {
        $tools = app(DatabaseTools::class);
        $binary = $tools->find(['mariadb-dump']);   // --ignore-table-data is MariaDB's; the image has it
        $ignore = [];
        foreach ($schemas as $schema) {
            foreach ($this->tables($admin, $schema) as $table) {
                if (in_array($table, $this->ignoredTables(), true)) {
                    $ignore[] = "--ignore-table-data={$schema}.{$table}";
                }
            }
        }
        $c = Provisioner::credentials();
        $command = [$binary, "--host={$c['host']}", "--port={$c['port']}", "--user={$c['user']}", ...$tools->extraArgs($binary),
            '--single-transaction', '--no-tablespaces', '--routines', '--triggers', ...$ignore, "--result-file={$to}", '--databases', ...$schemas];
        $process = new Process($command, null, ['MYSQL_PWD' => $c['password']], null, null);
        $process->run();
        if (! $process->isSuccessful() || ! $tools->dumpIsComplete($to)) {
            throw new RuntimeException('The database dump failed: '.substr(trim($process->getErrorOutput()), 0, 300));
        }
    }

    // ---- restore -----------------------------------------------------------------------------------------------

    /**
     * Replace this instance with the one in the zip. Throws, with the reason, before changing anything for every problem that can be
     * found beforehand (the zip, its checksums, its schema names, an instance that is already in use without $force).
     *
     * @param  ?string  $keysDir  for a backup made without keys: a folder holding `<warehouse id>/warehouse.key` (the layout of the warehouse-secrets
     *                            volume, so a copy of it will do); used for any warehouse the zip has no key for
     * @return array{warehouses: int, files: bool, keys: bool, notes: list<string>}
     */
    public function restore(string $zipPath, ?string $passphrase, bool $force, ?callable $log = null, ?string $keysDir = null): array
    {
        $log ??= fn (string $m) => null;
        $zip = $this->open($zipPath);
        $manifest = $this->manifest($zip);
        $work = rtrim(app(BackupService::class)->rootDir(), '/').'/instance/.restore-'.bin2hex(random_bytes(4));

        try {
            File::ensureDirectoryExists($work, 0700);
            $log('Unpacking and verifying the backup');
            $this->extract($zip, $manifest, $work, $passphrase);
            $info = json_decode((string) file_get_contents("{$work}/instance/instance.json"), true);
            if (! is_array($info) || ! isset($info['warehouses'])) {
                throw new RuntimeException('The backup has no readable instance.json.');
            }
            $this->checkSchemaNames($info);

            $admin = Provisioner::fromConfig()->pdo();
            $this->checkTarget($admin, $force);

            $log('Removing what this instance holds now');
            $existing = $this->existingWarehouses($admin);
            $this->dropEverything($admin, $existing, $info);

            $log('Loading the database');
            $this->load("{$work}/instance/dump.sql");
            DB::purge('control');
            DB::purge('accounts');
            $provisioner = new Provisioner($admin);
            $provisioner->ensureInstanceSchemas([
                'control' => config('database.connections.control'), 'accounts' => config('database.connections.accounts'),
            ], false);

            $notes = [];
            $keys = false;
            $files = (bool) ($manifest['includes_files'] ?? false);
            foreach (Warehouse::orderBy('id')->get() as $warehouse) {
                $log("Warehouse {$warehouse->id}: files, key and database user");
                File::deleteDirectory($warehouse->secretsPath());
                $keyFile = collect(["{$work}/keys/{$warehouse->id}/warehouse.key", $keysDir ? rtrim($keysDir, '/')."/{$warehouse->id}/warehouse.key" : null])
                    ->first(fn ($f) => $f && is_file($f) && Warehouse::validKey(trim((string) file_get_contents($f))));
                if ($keyFile) {
                    $warehouse->writeKey(trim((string) file_get_contents($keyFile)));
                    $keys = true;
                } else {
                    $notes[] = "Warehouse {$warehouse->id} ({$warehouse->display_name}) has no key in this backup: what it encrypts (settings) must be entered again.";
                }
                if ($files) {
                    File::deleteDirectory(dirname($warehouse->storagePath()));
                    $this->moveTree("{$work}/files/{$warehouse->id}", $warehouse->storagePath());
                }
                if ($this->schemaExists($admin, $warehouse->schema_name)) {
                    $provisioner->restoreAccess($warehouse);
                }
            }
            InstanceAuditLog::record('instance.restored', null, ['from' => basename($zipPath), 'warehouses' => count($info['warehouses']), 'files' => $files, 'keys' => $keys], 'command line');

            return ['warehouses' => count($info['warehouses']), 'files' => $files, 'keys' => $keys, 'notes' => $notes];
        } finally {
            File::deleteDirectory($work);
            $zip->close();
        }
    }

    private function open(string $path): ZipArchive
    {
        if (! is_file($path)) {
            throw new RuntimeException(basename($path).' was not found.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException(basename($path).' is not a valid zip archive (it may be damaged or incomplete).');
        }

        return $zip;
    }

    private function manifest(ZipArchive $zip): array
    {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('This does not look like a Relief Inventory backup (no readable manifest).');
        }
        if (($manifest['kind'] ?? 'warehouse') !== self::KIND) {
            throw new RuntimeException('This is a warehouse backup, not a whole-instance one. Restore it into a warehouse (Setup > Restore, or backup:restore).');
        }
        if (! is_int($manifest['format'] ?? null) || $manifest['format'] > BackupService::FORMAT) {
            throw new RuntimeException('This backup uses a newer backup format than this software understands. Update the software first.');
        }

        return $manifest;
    }

    /** Unpack every entry, checking size and SHA-256 as it streams. Nothing on the instance has changed until this has passed. */
    private function extract(ZipArchive $zip, array $manifest, string $work, ?string $passphrase): void
    {
        $total = array_sum(array_column($manifest['entries'] ?? [], 'size'));
        $free = @disk_free_space($work);
        if ($free !== false && $free < $total * 1.05 + 50 * 1024 * 1024) {
            throw new RuntimeException('Not enough free disk space to unpack this backup.');
        }
        foreach ($manifest['entries'] ?? [] as $entry) {
            $path = $entry['path'] ?? '';
            if (! is_string($path) || ! preg_match('#^(instance/(dump\.sql|instance\.json)|files/[A-Za-z0-9]{8}/[^\0]+|keys/[A-Za-z0-9]{8}/warehouse\.key)$#', $path) || str_contains($path, '..')) {
                throw new RuntimeException('The backup lists an unexpected file path: '.substr((string) $path, 0, 80));
            }
            $encrypted = str_starts_with($path, 'keys/');
            if ($encrypted) {
                if ($passphrase === null || $passphrase === '') {
                    throw new RuntimeException('This backup holds the warehouse keys, encrypted: give the passphrase it was made with.');
                }
                $zip->setPassword($passphrase);
            }
            $dest = "{$work}/{$path}";
            File::ensureDirectoryExists(dirname($dest), 0700);
            $in = $zip->getStream($path);
            $out = fopen($dest, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException($encrypted ? 'The passphrase is not correct for this backup.' : "Could not read {$path} from the backup.");
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
            }
            fclose($in);
            fclose($out);
            if ($size !== $entry['size'] || ! hash_equals($entry['sha256'], hash_final($hash))) {
                throw new RuntimeException($encrypted ? 'The passphrase is not correct for this backup.' : "The backup is damaged: {$path} does not match its checksum. Nothing was restored.");
            }
        }
        if (! is_file("{$work}/instance/dump.sql") || ! is_file("{$work}/instance/instance.json")) {
            throw new RuntimeException('The backup is missing its database dump.');
        }
        if (! app(DatabaseTools::class)->dumpIsComplete("{$work}/instance/dump.sql")) {
            throw new RuntimeException('The database dump in this backup is incomplete (no end-of-dump marker), so nothing was changed. Use an earlier backup.');
        }
    }

    /** The control and accounts schemas are named from this instance's own settings; the dump recreates them under the old names. */
    private function checkSchemaNames(array $info): void
    {
        foreach (['control' => 'RI_CONTROL_DATABASE', 'accounts' => 'RI_ACCOUNTS_DATABASE'] as $connection => $setting) {
            $here = config("database.connections.{$connection}.database");
            if (($info["{$connection}_schema"] ?? null) !== $here) {
                throw new RuntimeException("The backup's {$connection} schema is called {$info["{$connection}_schema"]} but this instance's is {$here}. Set DB_DATABASE (or {$setting}) here to match the old instance, then try again. Nothing was changed.");
            }
        }
    }

    /** A new instance is empty, or has only a default warehouse nobody has set up. Anything else is replaced only with $force. */
    private function checkTarget(PDO $admin, bool $force): void
    {
        $inUse = [];
        foreach ($this->existingWarehouses($admin) as $row) {
            if ($this->isSetUp($admin, $row['schema'])) {
                $inUse[] = $row['id'];
            }
        }
        if (! $force && (count($inUse) > 0 || count($this->existingWarehouses($admin)) > 1)) {
            throw new RuntimeException('This instance is already in use ('.count($this->existingWarehouses($admin)).' warehouse(s), '.count($inUse).' set up). A restore REPLACES all of it; add --force if that is what you want. Nothing was changed.');
        }
    }

    /** @return list<array{id: string, schema: string}> */
    private function existingWarehouses(PDO $admin): array
    {
        $control = config('database.connections.control.database');
        try {
            $rows = $admin->query('SELECT id, schema_name FROM '.$this->quote($control).'.warehouses')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];   // no control tables yet: a brand-new instance
        }

        return array_map(fn ($r) => ['id' => $r['id'], 'schema' => $r['schema_name']], $rows);
    }

    private function isSetUp(PDO $admin, string $schema): bool
    {
        try {
            return (bool) $admin->query('SELECT 1 FROM '.$this->quote($schema).'.setup_state WHERE completed_at IS NOT NULL LIMIT 1')->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** Drop the control and accounts schemas, and every warehouse that is there or that the backup brings (schema, user, key, files). */
    private function dropEverything(PDO $admin, array $existing, array $info): void
    {
        $ids = collect($existing)->pluck('id')->merge(collect($info['warehouses'])->pluck('id'))->unique();
        $schemas = collect($existing)->pluck('schema')->merge(collect($info['warehouses'])->pluck('schema'))
            ->push(config('database.connections.control.database'), config('database.connections.accounts.database'))->unique();
        foreach ($schemas as $schema) {
            $admin->exec('DROP DATABASE IF EXISTS '.$this->quote($schema));
        }
        foreach ($ids as $id) {
            $admin->exec('DROP USER IF EXISTS '.$admin->quote('ri_'.strtolower($id)).'@'.$admin->quote(config('warehouses.provisioning.user_host')));
            $stub = new Warehouse;
            $stub->id = $id;
            File::deleteDirectory($stub->secretsPath());
            File::deleteDirectory(dirname($stub->storagePath()));
        }
        DB::purge('control');
        DB::purge('accounts');
    }

    private function load(string $sqlFile): void
    {
        $tools = app(DatabaseTools::class);
        $binary = $tools->find(['mariadb']);
        $c = Provisioner::credentials();
        $process = new Process([$binary, "--host={$c['host']}", "--port={$c['port']}", "--user={$c['user']}", ...$tools->extraArgs($binary), '--sandbox', '--local-infile=0'], null, ['MYSQL_PWD' => $c['password']], null, null);
        $input = fopen($sqlFile, 'rb');
        $process->setTimeout(null);
        $process->setInput($input);
        $process->run();
        fclose($input);
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Loading the database dump failed: '.substr(trim($process->getErrorOutput()), 0, 400).' The instance is now EMPTY; run the restore again.');
        }
    }

    private function moveTree(string $from, string $to): void
    {
        if (! is_dir($from)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $dest = rtrim($to, '/').'/'.substr($file->getPathname(), strlen($from) + 1);
            File::ensureDirectoryExists(dirname($dest), 0750);
            if (! @rename($file->getPathname(), $dest) && ! copy($file->getPathname(), $dest)) {
                throw new RuntimeException('Could not write restored file '.substr($dest, strlen($to)));
            }
        }
    }

    // ---- small helpers -------------------------------------------------------------------------------------------

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function schemaExists(PDO $admin, string $schema): bool
    {
        $stmt = $admin->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$schema]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function tables(PDO $admin, string $schema): array
    {
        $stmt = $admin->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $stmt->execute([$schema]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
