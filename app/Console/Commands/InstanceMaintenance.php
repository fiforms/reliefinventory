<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Instance-wide maintenance mode: Laravel's `down` / `up`, with the audit log and the right words. Every warehouse answers 503 while it is on
 * (the software update uses it). To take ONE warehouse out of service use `warehouse:suspend --maintenance`, which touches nobody else. It is
 * only the web side: queued jobs and the scheduler keep running, and CLI commands (including these) are unaffected.
 */
class InstanceMaintenance extends Command
{
    protected $signature = 'instance:maintenance
        {action=status : on, off or status}
        {--secret= : on: a path segment that still reaches the site (https://host/<secret>), for the person doing the work}
        {--retry=60 : on: the Retry-After seconds sent with the 503}';

    protected $description = 'Put the whole instance into (or take it out of) maintenance mode. For one warehouse use warehouse:suspend --maintenance.';

    public function handle(): int
    {
        $down = app()->isDownForMaintenance();

        switch ($this->argument('action')) {
            case 'status':
                $this->line($down ? 'The instance is in maintenance mode.' : 'The instance is serving.');

                return self::SUCCESS;
            case 'on':
                if ($down) {
                    $this->components->warn('It is already in maintenance mode.');

                    return self::SUCCESS;
                }
                $args = ['--retry' => (int) $this->option('retry')] + ($this->option('secret') ? ['--secret' => $this->option('secret')] : []);
                Artisan::call('down', $args);
                InstanceAuditLog::record('instance.maintenance_on', null, ['bypass_secret' => (bool) $this->option('secret')]);
                $this->components->info('Maintenance mode is ON: every warehouse answers 503. `instance:maintenance off` ends it.');

                return self::SUCCESS;
            case 'off':
                if (! $down) {
                    $this->components->warn('It is not in maintenance mode.');

                    return self::SUCCESS;
                }
                Artisan::call('up');
                InstanceAuditLog::record('instance.maintenance_off');
                $this->components->info('Maintenance mode is off.');

                return self::SUCCESS;
            default:
                $this->components->error('The action is on, off or status.');

                return self::FAILURE;
        }
    }
}
