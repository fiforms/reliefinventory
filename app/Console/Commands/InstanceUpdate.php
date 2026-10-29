<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\InstanceUpdate as UpdateFlag;
use Illuminate\Console\Command;

/**
 * Ask the host-side updater (docker/updater/ri-updater.py) to check for, or install, a new image. This only sets the flag in the
 * control schema; the updater polls for it, and `instance:update status` shows how it went.
 */
class InstanceUpdate extends Command
{
    protected $signature = 'instance:update {action=status : check, apply or status}';

    protected $description = 'Request a check for (or an install of) a new Docker image from the host-side updater, or show its status.';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['check', 'apply', 'status'], true)) {
            $this->components->error('The action is check, apply or status.');

            return self::INVALID;
        }

        if ($action !== 'status') {
            if (! UpdateFlag::enabled()) {
                $this->components->error('Automatic updates are off: set RI_UPDATE_SECRET in compose.yaml and run the updater on the host (docker/updater/).');

                return self::FAILURE;
            }
            if (! UpdateFlag::request($action === 'apply' ? 'update' : 'check', 'command line')) {
                $this->components->warn('An update is already in progress.');

                return self::FAILURE;
            }
            $this->components->info('Requested. `instance:update status` shows how it goes.');
        }

        $this->table(['', ''], collect(UpdateFlag::current()->payload())->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v])->values()->all());

        return self::SUCCESS;
    }
}
