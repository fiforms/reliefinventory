<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\InstanceBackupSchedule;
use App\Services\InstanceBackupService;
use App\Services\Warehouses\ProvisioningClient;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The whole-instance backup (MULTI_WAREHOUSE.md §11). Needs the database administrator's password, so it runs in the provisioner
 * container: `docker compose exec provisioner php artisan instance:backup --files --with-keys`. `--via-provisioner` is for the other
 * containers (the web container's pre-upgrade backup): it asks the provisioner to take the fixed `premigrate` backup and waits.
 * `--scheduled` is for the scheduler container, every hour: when Instance Admin's backup schedule says one is due, it asks the provisioner
 * for it (`backup-sched`) and waits; otherwise it does nothing.
 */
class InstanceBackup extends Command
{
    protected $signature = 'instance:backup
        {--dest= : Directory for the zip (default: <backup dir>/instance)}
        {--label= : Appended to the file name}
        {--files : Include every warehouse\'s uploaded files}
        {--with-keys : Include the warehouse keys, encrypted with a passphrase (asked for, or --passphrase-file)}
        {--passphrase-file= : A file holding the passphrase, for --with-keys without a terminal}
        {--via-provisioner : Ask the provisioner container to take the pre-upgrade backup (no other options apply)}
        {--scheduled : Ask the provisioner for the scheduled backup if the schedule says one is due (for the scheduler container; no other options apply)}';

    protected $description = 'Back up the whole instance (control + accounts + every warehouse) into one zip; run in the provisioner container';

    public function handle(InstanceBackupService $backups): int
    {
        if ($this->option('scheduled')) {
            return $this->scheduled();
        }
        if ($this->option('via-provisioner')) {
            return $this->viaProvisioner();
        }

        $passphrase = null;
        if ($this->option('with-keys')) {
            $passphrase = $this->passphrase();
            if ($passphrase === null) {
                return self::FAILURE;
            }
        }

        try {
            $result = $backups->create($this->option('dest') ?: $backups->dir(), $this->option('label') ?: null, (bool) $this->option('files'), $passphrase, fn ($m) => $this->line($m));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup written: '.$result['path'].' ('.round($result['size'] / 1048576, 1).' MB, '.$result['warehouses'].' warehouse(s))');
        if ($passphrase === null) {
            $this->warn('The warehouse keys are NOT in this backup (no --with-keys). Keep them with it some other way, or encrypted values cannot be read after a restore.');
        } else {
            $this->warn('Keep the passphrase separately from the backup. Without it the keys cannot be restored.');
        }

        return self::SUCCESS;
    }

    private function passphrase(): ?string
    {
        if ($file = $this->option('passphrase-file')) {
            $value = is_readable($file) ? trim((string) file_get_contents($file)) : '';
            if ($value === '') {
                $this->error("{$file} is not readable or is empty.");

                return null;
            }

            return $value;
        }
        if (! $this->input->isInteractive()) {
            $this->error('--with-keys needs a passphrase: run it in a terminal or give --passphrase-file.');

            return null;
        }
        $value = (string) $this->secret('Passphrase for the warehouse keys');
        if (strlen($value) < 12 || $value !== (string) $this->secret('Again')) {
            $this->error('The passphrase must be at least 12 characters and typed the same twice.');

            return null;
        }

        return $value;
    }

    /** 2 = the provisioner can't be asked yet (control schema not migrated: the first upgrade to this version), so the caller falls back. */
    private function viaProvisioner(): int
    {
        try {
            $done = app(ProvisioningClient::class)->request(null, 'instance-backup', 'upgrade', 3600);
        } catch (QueryException) {
            $this->warn('The control schema is not ready, so the provisioner cannot be asked.');

            return 2;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($done as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /** Needs no administrator password: the schedule is a control-schema row, and the provisioner re-checks it before making anything. */
    private function scheduled(): int
    {
        try {
            if (InstanceBackupSchedule::current()->due() === []) {
                $this->line('No instance backup is due.');

                return self::SUCCESS;
            }
            $done = app(ProvisioningClient::class)->request(null, 'backup-sched', 'scheduler', 3600);
        } catch (QueryException) {
            $this->warn('The control schema is not ready, so the schedule cannot be read.');

            return 2;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($done as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
