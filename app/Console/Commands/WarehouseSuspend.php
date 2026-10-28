<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;

/**
 * Take ONE warehouse out of service, leaving every other warehouse alone (instance-wide is `instance:maintenance`). It then answers 503 to
 * everything (logins, machine URLs, the setup pages) and leaves the chooser; nothing is deleted, and `warehouse:activate` brings it back.
 * `--maintenance` is the same thing recorded as a short, planned window rather than a suspension.
 */
class WarehouseSuspend extends Command
{
    use InstanceCommand;

    protected $signature = 'warehouse:suspend {warehouse : the warehouse id} {--maintenance : Record it as maintenance rather than a suspension} {--reason= : Kept in the audit log}';

    protected $description = 'Take one warehouse out of service (status suspended, or maintenance); warehouse:activate brings it back.';

    public function handle(): int
    {
        if (! $warehouse = $this->warehouseOrNull($this->argument('warehouse'))) {
            return self::FAILURE;
        }
        $to = $this->option('maintenance') ? 'maintenance' : 'suspended';

        if (! in_array($warehouse->status, ['active', 'suspended', 'maintenance'], true)) {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}, which is not serving anyone already or is in the middle of something else; only an active (or already suspended) warehouse is suspended.");

            return self::FAILURE;
        }
        if ($warehouse->status === $to) {
            $this->components->warn("{$warehouse->display_name} is already {$to}.");

            return self::SUCCESS;
        }

        $was = $warehouse->status;
        $warehouse->forceFill(['status' => $to])->save();
        InstanceAuditLog::record('warehouse.suspended', $warehouse, array_filter(['was' => $was, 'now' => $to, 'reason' => $this->option('reason')]));
        $this->components->info("{$warehouse->display_name} is now {$to}: it answers nobody until `php artisan warehouse:activate {$warehouse->id}`.");
        if ($warehouse->is_default) {
            $this->components->warn('This is the default warehouse: the unprefixed machine URLs (print clients, R Stations paired before there were several warehouses) stop working too.');
        }

        return self::SUCCESS;
    }
}
