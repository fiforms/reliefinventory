<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Control\InstanceAuditLog;
use App\Services\Warehouses\SetupCodes;
use Illuminate\Console\Command;

/**
 * The setup code of a warehouse awaiting its first administrator (MULTI_WAREHOUSE.md §7): issue a new one (the old one stops working; only a
 * hash is stored, so a lost code is replaced, never recovered), revoke it, or see whether one is waiting. The same as `warehouse:create --new-code`.
 */
class WarehouseSetupCode extends Command
{
    use InstanceCommand;

    protected $signature = 'warehouse:setup-code {warehouse : the warehouse id} {--revoke : Revoke the waiting code} {--status : Only say whether a code is waiting}';

    protected $description = 'Issue a new setup code for a warehouse awaiting setup, revoke it, or see whether one is waiting.';

    public function handle(SetupCodes $codes): int
    {
        if (! $warehouse = $this->warehouseOrNull($this->argument('warehouse'))) {
            return self::FAILURE;
        }

        if ($this->option('status')) {
            $pending = $codes->pending($warehouse);
            $this->line($pending ? "A code is waiting for {$warehouse->display_name}, valid until {$pending->expires_at}." : "No setup code is waiting for {$warehouse->display_name}.");

            return self::SUCCESS;
        }
        if ($this->option('revoke')) {
            $count = $codes->revoke($warehouse);
            InstanceAuditLog::record('warehouse.setup_code_revoked', $warehouse, ['revoked' => $count]);
            $this->components->info($count ? 'The setup code was revoked.' : 'No setup code was waiting.');

            return self::SUCCESS;
        }
        if (! $warehouse->isAwaitingSetup()) {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}; only a warehouse awaiting setup has a setup code. To give someone access to one that is set up, `warehouse:admin`.");

            return self::FAILURE;
        }

        [$code] = $codes->issue($warehouse, 'warehouse:setup-code');
        InstanceAuditLog::record('warehouse.setup_code_issued', $warehouse);
        $this->components->info("A new setup code for {$warehouse->display_name} (any earlier one no longer works).");
        $this->line('  Address:    '.$warehouse->setupUrl());
        $this->line("  Setup code: {$code}  (shown once; valid ".config('warehouses.setup_code_days').' days)');

        return self::SUCCESS;
    }
}
