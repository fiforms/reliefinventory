<?php

// MULTI_WAREHOUSE.md §7: a warehouse's own database user, the provisioner that makes it, and the guarantee that the user
// reaches only its own schema. Uses the provisioning test user from dev/TESTING.md (it can create users and
// manages only schemas named test_reliefinventory*); everything the tests create is dropped again.

use App\Console\Commands\InstanceMigrate;
use App\Models\Control\ProvisioningCommand;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\Provisioner;
use App\Services\Warehouses\ProvisioningClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $this->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $this->markTestSkipped('The provisioning test user (dev/TESTING.md) does not exist here.');
    }
    $this->prefix = 'test_reliefinventory_prov'.bin2hex(random_bytes(2));
    config(['warehouses.provisioning' => [
        'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
        'schema_prefix' => $this->prefix, 'user_host' => 'localhost', 'max_user_connections' => 7,
    ] + config('warehouses.provisioning')]);
    $this->provisioner = new Provisioner($this->admin);
    $this->made = [];

    $this->newWarehouse = function (array $attributes = []) {
        $id = Warehouse::newId();
        $warehouse = new Warehouse($attributes + ['schema_name' => Provisioner::standardSchemaName($id), 'display_name' => "Prov {$id}", 'status' => 'provisioning']);
        $warehouse->id = $id;
        $warehouse->save();
        $this->made[] = $warehouse;

        return $warehouse;
    };
    // Whether the warehouse's database user exists. The test provisioner can't read mysql.user, so ask it to make the user:
    // that fails only when it is already there (and the test then removes what it made).
    $this->userExists = function (Warehouse $warehouse) {
        $account = "'".Provisioner::userName($warehouse)."'@'localhost'";
        try {
            $this->admin->exec("CREATE USER {$account}");
            $this->admin->exec("DROP USER {$account}");

            return false;
        } catch (PDOException) {
            return true;
        }
    };
    // The warehouse's connection as its own user: what the application will use.
    $this->connectAs = function (Warehouse $warehouse, ?string $schema = null) {
        $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));

        return new PDO(
            "mysql:host={$base['host']};port={$base['port']};dbname=".($schema ?? $warehouse->schema_name),
            Provisioner::userName($warehouse),
            trim(file_get_contents($warehouse->secretsPath('db_password'))),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    };
});

afterEach(function () {
    foreach ($this->made ?? [] as $warehouse) {
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$warehouse->schema_name.'`');
        $this->admin->exec('DROP DATABASE IF EXISTS `'.Provisioner::standardSchemaName($warehouse->id).'`');
        $this->admin->exec("DROP USER IF EXISTS 'ri_".strtolower($warehouse->id)."'@'localhost'");
        File::deleteDirectory($warehouse->secretsPath());
    }
    if (isset($this->admin)) {
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$this->prefix.'`');
    }
});

test('a new warehouse gets its schema, a user of its own and a password file', function () {
    $warehouse = ($this->newWarehouse)();

    $done = $this->provisioner->create($warehouse);

    expect($this->provisioner->schemaExists($warehouse->schema_name))->toBeTrue()
        ->and(($this->userExists)($warehouse))->toBeTrue()
        ->and(is_readable($warehouse->secretsPath('db_password')))->toBeTrue()
        ->and(implode(' ', $done))->toContain('created schema');

    $pdo = ($this->connectAs)($warehouse);
    $pdo->exec('CREATE TABLE things (id INT PRIMARY KEY, name VARCHAR(20))');
    $pdo->exec("INSERT INTO things VALUES (1, 'a')");
    $pdo->exec("UPDATE things SET name = 'b'");
    expect($pdo->query('SELECT name FROM things')->fetchColumn())->toBe('b');
    $pdo->exec('ALTER TABLE things ADD COLUMN extra INT NULL');
    $pdo->exec('DROP TABLE things');
});

test("a warehouse's user can't reach another schema, create views, triggers or databases, or be used for FILE access", function () {
    $a = ($this->newWarehouse)();
    $b = ($this->newWarehouse)();
    $this->provisioner->create($a);
    $this->provisioner->create($b);
    ($this->connectAs)($b)->exec('CREATE TABLE secret (v INT)');
    ($this->connectAs)($b)->exec('INSERT INTO secret VALUES (42)');

    $as = ($this->connectAs)($a);
    expect(fn () => $as->query("SELECT * FROM `{$b->schema_name}`.secret"))->toThrow(PDOException::class)
        ->and(fn () => ($this->connectAs)($a, $b->schema_name))->toThrow(PDOException::class)
        ->and(fn () => $as->exec("CREATE DATABASE `{$a->schema_name}_x`"))->toThrow(PDOException::class)
        ->and(fn () => $as->exec('CREATE VIEW v AS SELECT 1'))->toThrow(PDOException::class)
        ->and(function () use ($as) {
            $as->exec('CREATE TABLE t (id INT)');
            $as->exec('CREATE TRIGGER trg BEFORE INSERT ON t FOR EACH ROW SET NEW.id = 1');
        })->toThrow(PDOException::class)
        ->and(fn () => $as->exec('CREATE USER evil'))->toThrow(PDOException::class)
        ->and(fn () => $as->exec('GRANT ALL ON *.* TO CURRENT_USER'))->toThrow(PDOException::class)
        ->and(fn () => $as->query("SELECT LOAD_FILE('/etc/passwd')")->fetchColumn() ?: throw new PDOException('no file access'))->toThrow(PDOException::class);
});

test('the user is limited in connections', function () {
    $warehouse = ($this->newWarehouse)();
    $this->provisioner->create($warehouse);

    $grants = implode(' ', ($this->connectAs)($warehouse)->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN));
    expect($grants)->toContain('MAX_USER_CONNECTIONS 7');
});

test('provisioning twice keeps the password, and repairs a user whose password has drifted', function () {
    $warehouse = ($this->newWarehouse)();
    $this->provisioner->create($warehouse);
    $password = file_get_contents($warehouse->secretsPath('db_password'));

    $this->admin->exec("ALTER USER 'ri_".strtolower($warehouse->id)."'@'localhost' IDENTIFIED BY 'something else'");
    $again = $this->provisioner->create($warehouse);

    expect(file_get_contents($warehouse->secretsPath('db_password')))->toBe($password)
        ->and($again)->not->toContain('wrote its database password file');
    expect(($this->connectAs)($warehouse)->query('SELECT 1')->fetchColumn())->toEqual(1);
});

test('a crash after the user was made but before the file was written is repaired', function () {
    $warehouse = ($this->newWarehouse)();
    $this->provisioner->create($warehouse);
    unlink($warehouse->secretsPath('db_password'));

    $this->provisioner->create($warehouse);

    expect(($this->connectAs)($warehouse)->query('SELECT 1')->fetchColumn())->toEqual(1);
});

test('only a warehouse still being provisioned can be created, and only under its standard name', function () {
    $active = ($this->newWarehouse)(['status' => 'active']);
    $forged = ($this->newWarehouse)(['schema_name' => $this->prefix.'_control']);

    expect(fn () => $this->provisioner->create($active))->toThrow(RuntimeException::class, 'not waiting to be provisioned')
        ->and(fn () => $this->provisioner->create($forged))->toThrow(RuntimeException::class, 'not '.$this->prefix.'_');
    expect($this->provisioner->schemaExists($forged->schema_name))->toBeFalse();
});

test('drop removes the schema, the user and the password, and only for an archived or unfinished warehouse', function () {
    $warehouse = ($this->newWarehouse)(['status' => 'active']);
    $warehouse->forceFill(['status' => 'provisioning'])->save();
    $this->provisioner->create($warehouse);
    $warehouse->forceFill(['status' => 'active'])->save();

    expect(fn () => $this->provisioner->drop($warehouse))->toThrow(RuntimeException::class, 'may not be dropped');

    $warehouse->forceFill(['status' => 'archived'])->save();
    $this->provisioner->drop($warehouse);

    expect($this->provisioner->schemaExists($warehouse->schema_name))->toBeFalse()
        ->and(($this->userExists)($warehouse))->toBeFalse()
        ->and(is_readable($warehouse->secretsPath('db_password')))->toBeFalse();
});

test('the default warehouse is never dropped, nor a schema outside the standard naming', function () {
    $default = ($this->newWarehouse)(['status' => 'archived']);
    $default->forceFill(['is_default' => true])->save();
    $forged = ($this->newWarehouse)(['status' => 'archived', 'schema_name' => $this->prefix.'_accounts']);

    expect(fn () => $this->provisioner->drop($default))->toThrow(RuntimeException::class)
        ->and(fn () => $this->provisioner->drop($forged))->toThrow(RuntimeException::class, 'not '.$this->prefix.'_');
});

test("adopting the default warehouse renames the instance's schema, keeping every table, row and foreign key", function () {
    $this->admin->exec("CREATE DATABASE `{$this->prefix}`");
    $this->admin->exec("CREATE TABLE `{$this->prefix}`.parents (id INT PRIMARY KEY, name VARCHAR(20)) ENGINE=InnoDB");
    $this->admin->exec("CREATE TABLE `{$this->prefix}`.kids (id INT PRIMARY KEY, parent_id INT, CONSTRAINT kids_parent FOREIGN KEY (parent_id) REFERENCES parents (id)) ENGINE=InnoDB");
    $this->admin->exec("INSERT INTO `{$this->prefix}`.parents VALUES (1, 'p')");
    $this->admin->exec("INSERT INTO `{$this->prefix}`.kids VALUES (1, 1)");
    $warehouse = ($this->newWarehouse)(['schema_name' => $this->prefix, 'status' => 'active']);
    $warehouse->forceFill(['is_default' => true])->save();

    $done = $this->provisioner->adopt($warehouse);

    $target = Provisioner::standardSchemaName($warehouse->id);
    expect($warehouse->fresh()->schema_name)->toBe($target)
        ->and(implode(' ', $done))->toContain('renamed schema')
        ->and($this->provisioner->schemaExists($this->prefix))->toBeTrue();   // left behind, empty
    $pdo = ($this->connectAs)($warehouse->fresh());
    expect($pdo->query('SELECT name FROM parents')->fetchColumn())->toBe('p')
        ->and($pdo->query('SELECT COUNT(*) FROM kids')->fetchColumn())->toEqual(1);
    expect(fn () => $pdo->exec('INSERT INTO kids VALUES (2, 99)'))->toThrow(PDOException::class);   // the foreign key came along
    expect($this->admin->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->prefix}'")->fetchColumn())->toEqual(0);

    // Running it again changes nothing.
    expect(implode(' ', $this->provisioner->adopt($warehouse->fresh())))->not->toContain('renamed');
});

test('adopt finishes a rename that stopped before the control row was updated', function () {
    $this->admin->exec("CREATE DATABASE `{$this->prefix}`");
    $this->admin->exec("CREATE TABLE `{$this->prefix}`.t (id INT PRIMARY KEY)");
    $warehouse = ($this->newWarehouse)(['schema_name' => $this->prefix, 'status' => 'active']);
    $warehouse->forceFill(['is_default' => true])->save();
    $target = Provisioner::standardSchemaName($warehouse->id);
    $this->admin->exec("CREATE DATABASE `{$target}`");
    $this->admin->exec("RENAME TABLE `{$this->prefix}`.t TO `{$target}`.t");

    $this->provisioner->adopt($warehouse);

    expect($warehouse->fresh()->schema_name)->toBe($target)
        ->and(($this->connectAs)($warehouse->fresh())->query('SELECT COUNT(*) FROM t')->fetchColumn())->toEqual(0);
});

test('adopt refuses to rename a schema that is not the instance\'s own, or to guess between two with data', function () {
    $warehouse = ($this->newWarehouse)(['schema_name' => $this->prefix.'_control', 'status' => 'active']);
    $warehouse->forceFill(['is_default' => true])->save();
    expect(fn () => $this->provisioner->adopt($warehouse))->toThrow(RuntimeException::class, 'only the instance');

    $this->admin->exec("CREATE DATABASE `{$this->prefix}`");
    $this->admin->exec("CREATE TABLE `{$this->prefix}`.t (id INT)");
    $other = ($this->newWarehouse)(['schema_name' => $this->prefix, 'status' => 'active']);
    $other->forceFill(['is_default' => true])->save();
    $target = Provisioner::standardSchemaName($other->id);
    $this->admin->exec("CREATE DATABASE `{$target}`");
    $this->admin->exec("CREATE TABLE `{$target}`.u (id INT)");
    expect(fn () => $this->provisioner->adopt($other))->toThrow(RuntimeException::class, 'refusing to guess');
});

test('the provisioner answers commands from the control schema, and re-checks them against the warehouse row', function () {
    $good = ($this->newWarehouse)();
    $bad = ($this->newWarehouse)(['status' => 'active']);
    $goodCommand = ProvisioningCommand::create(['warehouse_id' => $good->id, 'command' => 'create']);
    $badCommand = ProvisioningCommand::create(['warehouse_id' => $bad->id, 'command' => 'create']);
    $unknown = ProvisioningCommand::create(['warehouse_id' => $good->id, 'command' => 'format-everything']);

    // The instance schemas step needs grants the test user may not have on these names; the commands are what's under test.
    Artisan::call('provisioner:work', ['--once' => true]);

    expect($goodCommand->refresh()->status)->toBe('done')
        ->and($badCommand->refresh()->status)->toBe('failed')
        ->and($badCommand->message)->toContain('not waiting to be provisioned')
        ->and($unknown->refresh()->status)->toBe('failed')
        ->and(($this->userExists)($good))->toBeTrue()
        ->and(($this->userExists)($bad))->toBeFalse();
});

test('a command left running by a provisioner that stopped is run again', function () {
    $warehouse = ($this->newWarehouse)();
    $command = ProvisioningCommand::create(['warehouse_id' => $warehouse->id, 'command' => 'create']);
    $command->forceFill(['status' => 'running'])->save();

    Artisan::call('provisioner:work', ['--once' => true]);

    expect($command->refresh()->status)->toBe('done');
});

test('instance:migrate asks nothing of the provisioner outside Docker', function () {
    expect(ProvisioningClient::applies())->toBeFalse();
    expect(InstanceMigrate::INSTANCE_SCHEMAS)->toHaveKeys(['control', 'accounts']);
});
