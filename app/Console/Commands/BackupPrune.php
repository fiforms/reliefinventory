<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupPrune extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete on-demand backup zips past their expiry';

    public function handle(BackupService $service): int
    {
        $this->info('Deleted '.$service->prune().' expired backup(s).');

        return self::SUCCESS;
    }
}
