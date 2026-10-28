<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\ProvisioningCommand;
use App\Services\Warehouses\Provisioner;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The provisioner (MULTI_WAREHOUSE.md §7): the one process that holds the database administrator credential. Makes the
 * instance's own schemas when it starts, then answers the commands the web container writes into the control schema
 * (create / adopt / drop a warehouse's schema and database user), each re-checked against the warehouse's own row.
 * It reads plain rows and unserializes nothing, so a forged row is the worst the web container can hand it. Also
 * keeps MariaDB's slow-log table from growing forever, once an hour.
 */
class ProvisionerWork extends Command
{
    protected $signature = 'provisioner:work {--once : Answer what is waiting and exit}';

    protected $description = 'Docker: the provisioner — the only process with the database administrator credential.';

    public function handle(): int
    {
        $provisioner = $this->connect();
        if (! $provisioner) {
            return self::FAILURE;
        }

        $schemas = [];
        foreach (InstanceMigrate::INSTANCE_SCHEMAS as $connection => $unused) {
            $schemas[$connection] = config("database.connections.{$connection}");
        }
        foreach ($provisioner->ensureInstanceSchemas($schemas) as $line) {
            $this->info($line);
        }

        // A command this process was in the middle of when it stopped: every step is idempotent, so run it again.
        $this->guarded(fn () => ProvisioningCommand::where('status', 'running')->update(['status' => 'pending']));

        $nextPrune = 0;
        do {
            while ($command = $this->guarded(fn () => $this->claim())) {
                $this->answer($provisioner, $command);
            }
            if (time() >= $nextPrune) {
                if ($pruned = $provisioner->pruneSlowLog()) {
                    $this->info("Emptied the slow log table ({$pruned} rows).");
                }
                $nextPrune = time() + 3600;
            }
            if (! $this->option('once')) {
                sleep(2);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** Wait for the database to accept the administrator (it is starting), then return. */
    private function connect(): ?Provisioner
    {
        $deadline = time() + 120;
        while (true) {
            try {
                return Provisioner::fromConfig();
            } catch (Throwable $e) {
                if (time() >= $deadline || $this->option('once')) {
                    $this->error('Cannot connect as the database administrator: '.$e->getMessage());

                    return null;
                }
                sleep(2);
            }
        }
    }

    /** The oldest pending command, taken so that nothing else answers it. */
    private function claim(): ?ProvisioningCommand
    {
        $next = ProvisioningCommand::where('status', 'pending')->orderBy('id')->first();
        if (! $next) {
            return null;
        }
        $taken = ProvisioningCommand::whereKey($next->id)->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now()]);

        return $taken ? $next->refresh() : null;
    }

    private function answer(Provisioner $provisioner, ProvisioningCommand $command): void
    {
        try {
            $done = $provisioner->run($command);
            $command->forceFill(['status' => 'done', 'message' => implode("\n", $done), 'finished_at' => now()])->save();
            $this->info("{$command->command} {$command->warehouse_id}: ".implode('; ', $done));
        } catch (Throwable $e) {
            $command->forceFill(['status' => 'failed', 'message' => $e->getMessage(), 'finished_at' => now()])->save();
            $this->error("{$command->command} {$command->warehouse_id} failed: ".$e->getMessage());
        }
    }

    /** The control schema doesn't have its tables until the web container has migrated it: wait quietly. */
    private function guarded(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException) {
            return null;
        }
    }
}
