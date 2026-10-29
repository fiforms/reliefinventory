<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\InstanceBackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restore a whole-instance backup (instance:backup) onto a new instance: `docker compose run --rm provisioner php artisan restore:instance
 * /backups/instance/<file>.zip`, with every other container stopped. Needs the database administrator's password, so only the provisioner
 * container can run it. It replaces everything this instance holds; see InstanceBackupService.
 */
class RestoreInstance extends Command
{
    protected $signature = 'restore:instance
        {zip : The whole-instance backup zip}
        {--passphrase-file= : A file holding the passphrase the keys were encrypted with (otherwise asked for when the backup has keys)}
        {--keys-dir= : For a backup made without keys: a folder with <warehouse id>/warehouse.key files (a copy of the old warehouse-secrets volume)}
        {--recovery-key-file= : A file holding the printed recovery key (RK1-…) that opens the sealed warehouse keys; the downloaded recovery-key file works as it is (otherwise asked for when the backup has sealed keys)}
        {--without-keys : Restore a backup with sealed keys without opening them (each warehouse\'s encrypted settings must then be entered again)}
        {--force : Replace an instance that is already in use}';

    protected $description = 'Restore a whole-instance backup onto this (new) instance; run in the provisioner container';

    public function handle(InstanceBackupService $backups): int
    {
        set_time_limit(0);
        $passphrase = null;
        if ($file = $this->option('passphrase-file')) {
            $passphrase = is_readable($file) ? trim((string) file_get_contents($file)) : null;
        } elseif ($this->input->isInteractive()) {
            $passphrase = (string) $this->secret('Passphrase for the warehouse keys (leave empty if the backup has none)') ?: null;
        }
        if ($this->input->isInteractive() && ! $this->confirm('This REPLACES everything in this instance (every warehouse, every login). Continue?', false)) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        $recoveryKey = null;
        try {
            $about = $backups->inspect($this->argument('zip'));
            if ($about['sealed'] && ! $this->option('without-keys')) {
                if ($file = $this->option('recovery-key-file')) {
                    $recoveryKey = is_readable($file) ? (string) file_get_contents($file) : null;
                    if ($recoveryKey === null) {
                        $this->error("{$file} is not readable.");

                        return self::FAILURE;
                    }
                } elseif (! $this->option('keys-dir') && $this->input->isInteractive()) {
                    $this->line('This backup holds the warehouse keys, sealed to recovery key '.($about['fingerprint'] ?? '(unnamed)').'.');
                    $recoveryKey = (string) $this->secret('Recovery key (RK1-…, from the printout)') ?: null;
                }
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $backups->restore($this->argument('zip'), $passphrase, (bool) $this->option('force'), fn ($m) => $this->line($m), $this->option('keys-dir') ?: null, $recoveryKey, (bool) $this->option('without-keys'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$result['warehouses']} warehouse(s)".($result['files'] ? ' with their files' : '').($result['keys'] ? ' and keys' : '').'.');
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
        $this->line('Now start the other containers: the app migrates anything the new software needs.');

        return self::SUCCESS;
    }
}
