<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Command lines for the database client programs (dump and load), built from the app's default
 * connection. Shared by BackupService (mariadb-dump) and RestoreService (mariadb/mysql). The
 * password travels in MYSQL_PWD, never on the command line.
 */
class DatabaseTools
{
    private function connection(): array
    {
        return config('database.connections.'.config('database.default'));
    }

    private function find(array $names): string
    {
        foreach ($names as $name) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name)));
            if ($path !== '') {
                return $path;
            }
        }
        throw new RuntimeException(implode(' / ', $names).' is not installed on this server.');
    }

    /** --skip-ssl for the MariaDB client (it otherwise insists on verified TLS), nothing for MySQL's. */
    private function extraArgs(string $binary): array
    {
        $extra = config('backup.dump_args') ?? (str_starts_with(basename($binary), 'mariadb') ? '--skip-ssl' : '');

        return array_values(array_filter(explode(' ', (string) $extra)));
    }

    private function common(string $binary): array
    {
        $c = $this->connection();

        return ['--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], ...$this->extraArgs($binary)];
    }

    public function dumpCommand(string $resultFile): array
    {
        $binary = $this->find(['mariadb-dump', 'mysqldump']);

        return [$binary, ...$this->common($binary), '--single-transaction', '--no-tablespaces', '--routines', '--triggers',
            ...$this->liveStateArgs($binary), '--result-file='.$resultFile, $this->connection()['database']];
    }

    /**
     * Keep the structure of the live-state tables (sessions, cache, ...) but not their rows (config/backup.php). Only mariadb-dump has
     * --ignore-table-data; mysqldump's --ignore-table would drop the structure too, and a restore would then miss the tables until a migration
     * happened to recreate them, so with it they are dumped whole and emptied by the restore.
     */
    private function liveStateArgs(string $binary): array
    {
        if (! str_starts_with(basename($binary), 'mariadb')) {
            return [];
        }
        $database = $this->connection()['database'];
        try {
            $existing = collect(DB::select('SHOW TABLES'))->map(fn ($row) => array_values((array) $row)[0]);
        } catch (\Throwable) {
            return [];   // the dump itself will say what is wrong with the database
        }

        return $existing->intersect(config('backup.live_state_tables'))->map(fn ($table) => "--ignore-table-data={$database}.{$table}")->values()->all();
    }

    /**
     * mariadb-dump / mysqldump end a finished dump with "-- Dump completed on <time>" (their default comments; we never
     * pass --skip-comments). A dump cut off part-way — the server dropped, the disk filled, the process was killed —
     * lacks it, so this catches truncation even where an exit status was missed. Checked after a dump is written and
     * again before a restore wipes anything.
     */
    public function dumpIsComplete(string $file): bool
    {
        $size = @filesize($file);
        if (! $size) {
            return false;
        }
        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - 512));
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return preg_match('/^-- Dump completed on .+\s*\z/m', $tail) === 1;
    }

    /**
     * The client for loading a dump; give it the SQL on stdin.
     *
     * A restored zip is untrusted input (a warehouse admin supplies it). What it can do in SQL is limited by the
     * database user's grants (its own schema only, no FILE). What the grants can't limit is the *client program*:
     * it runs inside the app container and has `system`/`\!`, `source`/`\.`, `tee`/`pager` and LOAD DATA LOCAL INFILE,
     * which would read or write the container's files. --sandbox turns off every client command that touches the
     * file system, and --local-infile=0 the client side of LOAD DATA LOCAL. That is the whole restore security
     * model; the SQL itself is not parsed or filtered.
     */
    public function clientCommand(): array
    {
        $binary = $this->find(['mariadb']);

        return [$binary, ...$this->common($binary), '--sandbox', '--local-infile=0', $this->connection()['database']];
    }

    /** A Process for one of the commands above. Symfony's directly, so Process::fake() in tests never intercepts it. */
    public function process(array $command): Process
    {
        return new Process($command, null, ['MYSQL_PWD' => (string) ($this->connection()['password'] ?? '')], null, null);
    }
}
