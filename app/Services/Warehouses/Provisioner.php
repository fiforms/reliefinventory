<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\ProvisioningCommand;
use App\Models\Control\Warehouse;
use App\Services\InstanceBackupService;
use PDO;
use RuntimeException;

/**
 * Everything that needs the database administrator credential (MULTI_WAREHOUSE.md §7): creating and dropping a
 * warehouse's schema and its own database user, and the instance's own schemas. Only the provisioner process
 * holds that credential and builds one of these; the web, queue and scheduler containers never do.
 *
 * Every step is idempotent and looks at the state it finds, so a crash half-way is repaired by running it again.
 * A command is a request written by a less-trusted process (the web container can write the command table);
 * run() decides from the warehouse's own row whether it is allowed, and never from what the command says.
 */
class Provisioner
{
    /**
     * A warehouse user's grants on its own schema: an explicit list, not ALL. No TRIGGER, EVENT, CREATE ROUTINE or
     * CREATE VIEW (the app uses none, and a restore may not create any), never FILE or SUPER (§7).
     */
    public const GRANTS = 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES';

    public function __construct(private PDO $admin) {}

    /**
     * The database administrator's login, from config (a file in the Docker stack's secrets volume). Only the provisioner process
     * has the password; asking anywhere else fails.
     *
     * @return array{host: string, port: string|int, user: string, password: string}
     */
    public static function credentials(): array
    {
        $c = config('warehouses.provisioning');
        $base = config('database.connections.'.$c['base_connection']);
        $password = $c['admin_password'] ?? null;
        if ($password === null || $password === '') {
            $file = (string) $c['admin_password_file'];
            if (! is_readable($file)) {
                throw new RuntimeException("No database administrator password: {$file} is not readable. Only the provisioner has it.");
            }
            $password = trim((string) file_get_contents($file));
        }

        return ['host' => $base['host'], 'port' => $base['port'], 'user' => $c['admin_user'], 'password' => $password];
    }

    /** A connection as the administrator (see credentials()). */
    public static function fromConfig(): self
    {
        $c = self::credentials();

        return new self(new PDO(
            "mysql:host={$c['host']};port={$c['port']}",
            $c['user'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        ));
    }

    /** The connection, for the whole-instance backup and restore (MULTI_WAREHOUSE.md §11), which run in this process. */
    public function pdo(): PDO
    {
        return $this->admin;
    }

    /** A restored warehouse's database user and password file, which a backup never carries (the schema came with the dump). */
    public function restoreAccess(Warehouse $warehouse): array
    {
        return $this->ensure($warehouse);
    }

    public static function standardSchemaName(string $warehouseId): string
    {
        return config('warehouses.provisioning.schema_prefix').'_'.strtolower($warehouseId);
    }

    public static function userName(Warehouse $warehouse): string
    {
        return 'ri_'.strtolower($warehouse->id);
    }

    /**
     * Carry out a request, after checking it against the warehouse's own row.
     *
     * @return list<string> what was done
     */
    public function run(ProvisioningCommand $command): array
    {
        if ($command->command === 'instance-backup') {
            return app(InstanceBackupService::class)->premigrate();
        }
        $warehouse = Warehouse::find($command->warehouse_id)
            ?? throw new RuntimeException("There is no warehouse {$command->warehouse_id}.");

        return match ($command->command) {
            'create' => $this->create($warehouse),
            'adopt' => $this->adopt($warehouse),
            'drop' => $this->drop($warehouse),
            default => throw new RuntimeException("Unknown command {$command->command}."),
        };
    }

    /** A new warehouse: its schema, its user and its password file. Only while it is still being provisioned. */
    public function create(Warehouse $warehouse): array
    {
        if ($warehouse->is_default || $warehouse->status !== 'provisioning') {
            throw new RuntimeException("Warehouse {$warehouse->id} is not waiting to be provisioned (it is {$warehouse->status}).");
        }
        $this->requireStandardName($warehouse);

        return $this->ensure($warehouse);
    }

    /**
     * The default warehouse, which has always had the instance's own schema and user (MULTI_WAREHOUSE.md §15): the
     * schema is renamed to the standard name every other warehouse has, and it gets a user of its own. The old schema is
     * left behind, empty, because the instance's base connection (the entrypoint's probe) still names it.
     */
    public function adopt(Warehouse $warehouse): array
    {
        if (! $warehouse->is_default) {
            throw new RuntimeException("Warehouse {$warehouse->id} is not the default warehouse.");
        }
        $done = [];
        $target = self::standardSchemaName($warehouse->id);
        $old = $warehouse->schema_name;

        if ($old !== $target) {
            // The only schema it may be renamed from is the instance's own (what it has always been called).
            if ($old !== config('warehouses.provisioning.schema_prefix')) {
                throw new RuntimeException("Warehouse {$warehouse->id}'s schema is {$old}; only the instance's own schema is renamed.");
            }
            if (! $this->schemaExists($target) || $this->tables($target) === []) {
                if ($this->schemaExists($old)) {
                    $this->createSchema($target);
                    $tables = $this->tables($old);
                    if ($tables) {
                        $this->admin->exec('RENAME TABLE '.implode(', ', array_map(fn ($t) => $this->id($old).'.'.$this->id($t).' TO '.$this->id($target).'.'.$this->id($t), $tables)));
                    }
                    $done[] = "renamed schema {$old} to {$target} (".count($tables).' tables)';
                }
            } elseif ($this->schemaExists($old) && $this->tables($old) !== []) {
                throw new RuntimeException("Both {$old} and {$target} hold tables; refusing to guess which is the warehouse's data.");
            }
            // The data is under the new name (renamed now, or by an earlier run that stopped before this line).
            $warehouse->forceFill(['schema_name' => $target])->save();
        }

        return [...$done, ...$this->ensure($warehouse)];
    }

    /** A warehouse's schema and user, once it has been archived (or while a provisioning that never finished is cleaned up). */
    public function drop(Warehouse $warehouse): array
    {
        if ($warehouse->is_default || ! in_array($warehouse->status, ['archived', 'provisioning'], true)) {
            throw new RuntimeException("Warehouse {$warehouse->id} may not be dropped (it is {$warehouse->status}).");
        }
        $this->requireStandardName($warehouse);

        $this->admin->exec('DROP DATABASE IF EXISTS '.$this->id($warehouse->schema_name));
        $user = $this->account($warehouse);
        $this->admin->exec("DROP USER IF EXISTS {$user}");
        @unlink($warehouse->secretsPath('db_password'));

        return ["dropped schema {$warehouse->schema_name} and user ".self::userName($warehouse)];
    }

    /**
     * Schema, user, grants and password file, in that order, so the file is never there before the user it opens.
     * An existing password file is kept (the user is set to match it); a missing one is made.
     */
    private function ensure(Warehouse $warehouse): array
    {
        $done = [];
        $schema = $warehouse->schema_name;
        if (! $this->schemaExists($schema)) {
            $this->createSchema($schema);
            $done[] = "created schema {$schema}";
        }

        $file = $warehouse->secretsPath('db_password');
        $existing = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        $password = $existing !== '' ? $existing : bin2hex(random_bytes(24));

        $user = $this->account($warehouse);
        $max = max(0, (int) config('warehouses.provisioning.max_user_connections'));
        $this->admin->exec("CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$this->admin->quote($password)}");
        $this->admin->exec("ALTER USER {$user} IDENTIFIED BY {$this->admin->quote($password)} WITH MAX_USER_CONNECTIONS {$max}");
        $grantOn = '`'.str_replace(['`', '_'], ['``', '\_'], $schema).'`.*';
        try {
            $this->admin->exec("REVOKE ALL PRIVILEGES ON {$grantOn} FROM {$user}");
        } catch (\PDOException) {
            // nothing granted yet
        }
        $this->admin->exec('GRANT '.self::GRANTS." ON {$grantOn} TO {$user}");
        $done[] = 'user '.self::userName($warehouse).' is ready';

        if ($existing === '') {
            $this->writeSecret($file, $password);
            $done[] = 'wrote its database password file';
        }

        return $done;
    }

    /**
     * The instance's own schemas (control, accounts) and the base user's access to them, once, before anything can
     * be migrated. Also lets the base user read MariaDB's slow log for Setup > Testing Tools (instance-wide, §13).
     *
     * @param  array<string, array{database: string, username: string}>  $schemas  connection name => its config
     * @return list<string>
     */
    public function ensureInstanceSchemas(array $schemas, bool $slowLog = true): array
    {
        $done = [];
        foreach ($schemas as $name => $c) {
            $this->createSchema($c['database']);
            $user = $this->admin->quote($c['username']).'@'.$this->admin->quote(config('warehouses.provisioning.user_host'));
            $this->admin->exec('GRANT ALL PRIVILEGES ON `'.str_replace(['`', '_'], ['``', '\_'], $c['database']).'`.* TO '.$user);
            $done[] = "schema {$c['database']} is ready";
        }
        if ($slowLog && $schemas) {
            $user = $this->admin->quote(array_values($schemas)[0]['username']).'@'.$this->admin->quote(config('warehouses.provisioning.user_host'));
            try {
                $this->admin->exec("GRANT SELECT ON mysql.slow_log TO {$user}");
            } catch (\PDOException $e) {
                $done[] = 'could not grant read access to the slow log: '.$e->getMessage();
            }
        }

        return $done;
    }

    /** MariaDB's slow-log table is CSV: it never rotates and can only be truncated. Empty it once it passes $maxRows. */
    public function pruneSlowLog(int $maxRows = 20000): ?int
    {
        try {
            $rows = (int) $this->admin->query('SELECT COUNT(*) FROM mysql.slow_log')->fetchColumn();
            if ($rows > $maxRows) {
                $this->admin->exec('TRUNCATE TABLE mysql.slow_log');

                return $rows;
            }
        } catch (\PDOException) {
            // the server isn't writing the log to a table
        }

        return null;
    }

    public function schemaExists(string $schema): bool
    {
        $stmt = $this->admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$schema]);

        return (bool) $stmt->fetchColumn();
    }

    public function userExists(Warehouse $warehouse): bool
    {
        $stmt = $this->admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
        $stmt->execute([self::userName($warehouse)]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function tables(string $schema): array
    {
        $stmt = $this->admin->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $stmt->execute([$schema]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function createSchema(string $schema): void
    {
        $this->admin->exec('CREATE DATABASE IF NOT EXISTS '.$this->id($schema).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private function account(Warehouse $warehouse): string
    {
        return $this->admin->quote(self::userName($warehouse)).'@'.$this->admin->quote(config('warehouses.provisioning.user_host'));
    }

    private function id(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** A schema name is the standard one for the warehouse, whatever its row says: the row is writable from outside. */
    private function requireStandardName(Warehouse $warehouse): void
    {
        $expected = self::standardSchemaName($warehouse->id);
        if ($warehouse->schema_name !== $expected) {
            throw new RuntimeException("Warehouse {$warehouse->id}'s schema is {$warehouse->schema_name}, not {$expected}.");
        }
    }

    private function writeSecret(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }
        $tmp = $file.'.tmp';
        file_put_contents($tmp, $contents);
        chmod($tmp, 0640);
        rename($tmp, $file);
    }
}
