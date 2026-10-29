<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\BackupService;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * This warehouse's rollback snapshots, for the Docker scheduler (`warehouse:each "backup:scheduled"`, every hour) and a bare-metal install's
 * hourly systemd timer (scripts/update.sh --scheduled runs the same command): asks the warehouse's
 * BackupSchedule what is due now and makes ONE recorded zip carrying every tier it satisfies (BackupSchedule::due()). Asking every hour
 * is safe: nothing due means no dump, and a warehouse that was suspended at its hour still gets its backup when it comes back. A
 * failed attempt leaves the tier due, so the next hour tries again. The backup itself waits its turn behind other warehouses'
 * (BackupService::instanceLock()); retention is BackupService::prune(), run at the start of every backup and hourly by the scheduler.
 * A training warehouse is disposable and never gets snapshots.
 */
class BackupScheduled extends Command
{
    protected $signature = 'backup:scheduled';

    protected $description = 'Make this warehouse\'s scheduled snapshot, if its schedule says one is due.';

    public function handle(BackupService $service): int
    {
        if (app(WarehouseContext::class)->current()->is_training) {
            $this->line('A training warehouse is not backed up on a schedule.');

            return self::SUCCESS;
        }
        // RI_BACKUP_ENABLED=false: the whole installation's switch (Docker: the scheduler script also checks it; bare metal has only this).
        if (! config('backup.container.enabled')) {
            $this->line('Scheduled backups are turned off for this installation (RI_BACKUP_ENABLED).');

            return self::SUCCESS;
        }
        if (! Schema::hasTable('backup_schedule')) {
            $this->line('This warehouse has not been migrated to the backup schedule yet.');

            return self::SUCCESS;
        }

        $service->reapInterrupted();
        if (Backup::whereIn('status', Backup::ACTIVE)->exists()) {
            $this->line('A backup is already running; it will be asked again next hour.');

            return self::SUCCESS;
        }

        $schedule = BackupSchedule::current();
        $due = $schedule->due();
        if ($due === []) {
            $this->line('No snapshot is due.');

            return self::SUCCESS;
        }

        $backup = Backup::create(['status' => 'queued', 'scope' => $schedule->scopeFor($due), 'tiers' => implode(',', $due)]);
        $service->run($backup);
        $backup->refresh();

        $schedule->forceFill(['last_run_at' => now(), 'last_status' => $backup->status === 'complete' ? 'complete' : 'failed'])->save();
        if ($backup->status !== 'complete') {
            $this->error($backup->error ?: 'The scheduled backup failed.');

            return self::FAILURE;
        }
        $this->line($backup->path().' ('.implode(', ', $due).')');

        return self::SUCCESS;
    }
}
