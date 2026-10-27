<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * Docker start-up: create the control and accounts schemas (MULTI_WAREHOUSE.md §2) and let the app's
 * database user use them. Needs the database ROOT password, which only the Docker stack provides (a file in
 * the secrets volume, like SlowLogMaintain). Elsewhere (bare metal, tests) the schemas are created by hand
 * once — see scripts/TESTING.md — and this is a no-op.
 *
 * Interim: once warehouses are provisioned by their own worker with separate database users (phase 3),
 * this moves there and the web container stops needing the root password.
 */
class InstanceEnsureSchemas extends Command
{
    protected $signature = 'instance:ensure-schemas';

    protected $description = 'Docker: create the control and accounts schemas and grant the app user access to them.';

    public function handle(): int
    {
        $file = (string) config('slowlog.db_root_password_file');

        if (config('system.runtime') !== 'docker' || ! is_readable($file)) {
            $this->line('Not a Docker install with a readable root password; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $base = config('database.connections.'.config('database.default'));
            $pdo = new PDO(
                "mysql:host={$base['host']};port={$base['port']}",
                'root',
                trim((string) file_get_contents($file)),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            foreach (array_keys(InstanceMigrate::INSTANCE_SCHEMAS) as $connection) {
                $c = config("database.connections.{$connection}");
                $schema = str_replace('`', '``', $c['database']);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                // An exact name, so `_` is escaped: unescaped it is a wildcard in a grant.
                $pdo->exec('GRANT ALL PRIVILEGES ON `'.str_replace('_', '\_', $schema).'`.* TO '.$pdo->quote($c['username'])."@'%'");
                $this->info("Schema {$c['database']} is ready.");
            }
        } catch (Throwable $e) {
            $this->error('Could not create the instance schemas: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
