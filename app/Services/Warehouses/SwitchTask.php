<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;

/**
 * One piece of per-warehouse state that WarehouseContext changes when the current warehouse changes
 * (connection, key, storage, …). Same shape as spatie/laravel-multitenancy's SwitchTenantTask, so adopting
 * that package later stays possible (MULTI_WAREHOUSE.md §8).
 */
interface SwitchTask
{
    public function makeCurrent(Warehouse $warehouse): void;

    public function forgetCurrent(): void;
}
