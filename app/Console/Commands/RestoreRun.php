<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\RestoreService;
use Illuminate\Console\Command;

/** Started detached by the first-run wizard once the backup zip is fully uploaded, or (--snapshot) by a warehouse admin's rollback to one of the warehouse's own snapshots. */
class RestoreRun extends Command
{
    protected $signature = 'restore:run {id? : the completed upload id} {storage? : the completed upload id of a separate files backup} {--snapshot= : roll this warehouse back to this zip in its own backup folder (RestoreService::runSnapshot)}';

    protected $description = 'Restore an uploaded backup zip onto this (never set up) instance, or roll a warehouse back to one of its snapshots';

    public function handle(RestoreService $restore): int
    {
        set_time_limit(0);
        if ($snapshot = $this->option('snapshot')) {
            $restore->runSnapshot($snapshot);
        } elseif ($this->argument('id')) {
            $restore->run($this->argument('id'), $this->argument('storage'));
        } else {
            $this->error('Give an upload id or --snapshot=<file>.');

            return self::FAILURE;
        }

        return ($restore->status()['state'] ?? '') === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
