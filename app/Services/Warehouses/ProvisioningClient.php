<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\ProvisioningCommand;
use App\Models\Control\Warehouse;
use RuntimeException;

/**
 * The web side of provisioning (MULTI_WAREHOUSE.md §7): asks the provisioner, a separate process, to do what needs the
 * database administrator credential, and waits for its answer. It writes a plain row and holds no credential itself.
 */
class ProvisioningClient
{
    /** Whether warehouses get their own database users here: the Docker stack, which runs a provisioner. */
    public static function applies(): bool
    {
        return config('system.runtime') === 'docker';
    }

    /** Whether the warehouse still needs the provisioner: no database user of its own yet, or (the default one) an old schema name. */
    public static function needed(Warehouse $warehouse): bool
    {
        return ! is_readable($warehouse->secretsPath('db_password'))
            || ($warehouse->is_default && $warehouse->schema_name !== Provisioner::standardSchemaName($warehouse->id));
    }

    /** Ask for `$command` and wait until the provisioner has answered. @return list<string> what it did */
    public function request(Warehouse $warehouse, string $command, ?string $requestedBy = null): array
    {
        $row = ProvisioningCommand::create(['warehouse_id' => $warehouse->id, 'command' => $command, 'requested_by' => $requestedBy]);

        $deadline = time() + (int) config('warehouses.provisioning.wait_seconds');
        while (! $row->refresh()->isFinished()) {
            if (time() >= $deadline) {
                throw new RuntimeException("The provisioner did not answer `{$command}` for warehouse {$warehouse->id} in time. Is it running?");
            }
            usleep(500_000);
        }
        if ($row->status === 'failed') {
            throw new RuntimeException("Provisioning ({$command}) failed for warehouse {$warehouse->id}: {$row->message}");
        }

        return array_filter(explode("\n", (string) $row->message));
    }
}
