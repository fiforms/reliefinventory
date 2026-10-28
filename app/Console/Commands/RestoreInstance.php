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

        try {
            $result = $backups->restore($this->argument('zip'), $passphrase, (bool) $this->option('force'), fn ($m) => $this->line($m));
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
