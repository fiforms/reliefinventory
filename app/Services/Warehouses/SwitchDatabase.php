<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Makes the warehouse's own connection (`warehouse_<id>`: the instance's base connection pointed at its schema)
 * the default one, so Eloquent models and queries (and the database session/cache/queue drivers) need no
 * changes. Each warehouse keeps its own named connection rather than one connection being re-pointed, so
 * switching away and back never drops a connection — or the transaction open on it (a test's, or a request
 * that ran a job synchronously).
 */
class SwitchDatabase implements SwitchTask
{
    private string $baseName;

    private array $base;

    public function __construct()
    {
        $this->baseName = config('database.default');
        $this->base = config("database.connections.{$this->baseName}");
    }

    public static function connectionName(Warehouse $warehouse): string
    {
        return 'warehouse_'.strtolower($warehouse->id);
    }

    public function makeCurrent(Warehouse $warehouse): void
    {
        $name = self::connectionName($warehouse);
        $config = $warehouse->connectionConfig($this->base);

        // A changed configuration (a new database password file) must not keep using the old connection.
        if (config("database.connections.{$name}") != $config) {
            config(["database.connections.{$name}" => $config]);
            DB::purge($name);
        }
        DB::setDefaultConnection($name);
    }

    public function forgetCurrent(): void
    {
        DB::setDefaultConnection($this->baseName);
    }
}
