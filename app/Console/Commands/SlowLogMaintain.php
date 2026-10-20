<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * Container housekeeping for MariaDB's slow log (docker/compose.yaml makes the
 * server write it to the mysql.slow_log table). Needs the database ROOT
 * password, which only the Docker stack provides (a file in the secrets
 * volume) — everywhere else this is a no-op. Best effort: any failure is a
 * message, never a failed container start.
 *
 *   --grant   let the app's database user SELECT mysql.slow_log (idempotent) so
 *             Setup > Testing Tools can show it
 *   --prune   mysql.slow_log is a CSV table that never rotates and can't be
 *             DELETEd from, only truncated: empty it once it passes --max-rows
 */
class SlowLogMaintain extends Command
{
    protected $signature = 'slowlog:maintain {--grant : Grant the app user read access} {--prune : Truncate the table when it is too big} {--max-rows=20000}';

    protected $description = 'Docker housekeeping for the MariaDB slow log table (grant read access to the app, keep it from growing forever).';

    public function handle(): int
    {
        $file = (string) config('slowlog.db_root_password_file');

        if (config('system.runtime') !== 'docker' || ! is_readable($file)) {
            $this->line('Not a Docker install with a readable root password; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $conn = config('database.connections.'.config('database.default'));
            $pdo = new PDO(
                "mysql:host={$conn['host']};port={$conn['port']}",
                'root',
                trim((string) file_get_contents($file)),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            if ($this->option('grant')) {
                $pdo->exec('GRANT SELECT ON mysql.slow_log TO '.$pdo->quote($conn['username'])."@'%'");
                $this->info('Granted the app user read access to mysql.slow_log.');
            }

            if ($this->option('prune')) {
                $rows = (int) $pdo->query('SELECT COUNT(*) FROM mysql.slow_log')->fetchColumn();
                if ($rows > (int) $this->option('max-rows')) {
                    $pdo->exec('TRUNCATE TABLE mysql.slow_log');
                    $this->info("Truncated mysql.slow_log ({$rows} rows).");
                }
            }
        } catch (Throwable $e) {
            $this->warn('Slow log maintenance skipped: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
