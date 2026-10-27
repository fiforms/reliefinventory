<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Re-points the default database connection at the warehouse's schema, so Eloquent models and queries
 * (and the database session/cache/queue drivers) need no changes. The base settings are captured once;
 * every switch starts from them.
 */
class SwitchDatabase implements SwitchTask
{
    private string $name;

    private array $base;

    public function __construct()
    {
        $this->name = config('database.default');
        $this->base = config("database.connections.{$this->name}");
    }

    public function makeCurrent(Warehouse $warehouse): void
    {
        $this->apply($warehouse->connectionConfig($this->base));
    }

    public function forgetCurrent(): void
    {
        $this->apply($this->base);
    }

    private function apply(array $config): void
    {
        // Purging an unchanged connection would only drop it (and with it any open transaction, which is
        // what every RefreshDatabase test is inside of).
        if (config("database.connections.{$this->name}") == $config) {
            return;
        }

        config(["database.connections.{$this->name}" => $config]);
        DB::purge($this->name);
    }
}
