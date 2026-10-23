<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\TrainingReset;
use Illuminate\Console\Command;

/**
 * The detached half of Training Mode's "Turn off and reset" (App\Services\TrainingReset::start launches it): restores
 * the reset point taken when Training Mode was turned on. Not meant to be run by hand — use `backup:restore` for that.
 */
class TrainingResetRun extends Command
{
    protected $signature = 'training:reset {file : The reset point zip, in the training backup directory} {archive : The class archive the reset saved}';

    protected $description = 'Restore Training Mode\'s reset point (started by Setup > Training Mode)';

    protected $hidden = true;

    public function handle(TrainingReset $reset): int
    {
        set_time_limit(0);
        $reset->run((string) $this->argument('file'), (string) $this->argument('archive'));

        return $reset->status()['state'] === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
