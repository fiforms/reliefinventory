<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\RestoreService;
use Illuminate\Console\Command;

/** Started detached by the first-run wizard once the backup zip is fully uploaded. */
class RestoreRun extends Command
{
    protected $signature = 'restore:run {id : the completed upload id} {storage? : the completed upload id of a separate files backup}';

    protected $description = 'Restore an uploaded backup zip onto this (never set up) instance';

    public function handle(RestoreService $restore): int
    {
        set_time_limit(0);
        $restore->run($this->argument('id'), $this->argument('storage'));

        return ($restore->status()['state'] ?? '') === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
