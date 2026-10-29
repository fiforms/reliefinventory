<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\TrainingReset;
use Illuminate\Console\Command;

/**
 * The detached half of a training warehouse's reset (App\Services\TrainingReset::start launches it, with --warehouse): rebuilds that
 * warehouse from its seed. Not meant to be run by hand; `warehouse:training reset` is the way from a terminal.
 */
class TrainingResetRun extends Command
{
    protected $signature = 'training:reset {archive : The class archive the reset saved}';

    protected $description = 'Rebuild a training warehouse from its seed (started by Setup > Training Warehouse)';

    protected $hidden = true;

    public function handle(TrainingReset $reset): int
    {
        set_time_limit(0);
        $reset->run((string) $this->argument('archive'));

        return $reset->status()['state'] === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
