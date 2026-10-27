<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use Illuminate\Console\Command;

/** The warehouses this instance hosts, with the ids `--warehouse` takes (MULTI_WAREHOUSE.md §13). */
class WarehouseList extends Command
{
    protected $signature = 'warehouse:list';

    protected $description = 'List the warehouses this instance hosts.';

    public function handle(): int
    {
        $this->table(
            ['Id', 'Name', 'Schema', 'Status', 'Default', 'Training', 'Hostnames'],
            Warehouse::with('domains')->orderByDesc('is_default')->orderBy('created_at')->get()->map(fn (Warehouse $w) => [
                $w->id, $w->display_name, $w->schema_name, $w->status, $w->is_default ? 'yes' : '', $w->is_training ? 'yes' : '',
                $w->domains->pluck('hostname')->implode(', '),
            ]),
        );

        return self::SUCCESS;
    }
}
