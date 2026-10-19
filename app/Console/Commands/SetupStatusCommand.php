<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\FirstRunService;
use Illuminate\Console\Command;

class SetupStatusCommand extends Command
{
    protected $signature = 'setup:status';

    protected $description = 'Print "needs-setup" if the first-run wizard is still pending, otherwise "complete" (used by the Docker entrypoint).';

    public function handle(FirstRunService $firstRun): int
    {
        $this->line($firstRun->needsSetup() ? 'needs-setup' : 'complete');

        return self::SUCCESS;
    }
}
