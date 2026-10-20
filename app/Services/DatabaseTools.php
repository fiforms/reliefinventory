<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

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
            '--result-file='.$resultFile, $this->connection()['database']];
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

    /** The interactive client; give it the SQL on stdin. */
    public function clientCommand(): array
    {
        $binary = $this->find(['mariadb', 'mysql']);

        return [$binary, ...$this->common($binary), $this->connection()['database']];
    }

    /** A Process for one of the commands above. Symfony's directly, so Process::fake() in tests never intercepts it. */
    public function process(array $command): Process
    {
        return new Process($command, null, ['MYSQL_PWD' => (string) ($this->connection()['password'] ?? '')], null, null);
    }
}
