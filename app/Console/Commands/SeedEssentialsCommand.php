<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\FirstRunService;
use Illuminate\Console\Command;

class SeedEssentialsCommand extends Command
{
    protected $signature = 'setup:seed-essentials';

    protected $description = 'Seed the essential lookup data (statuses, units, categories, item families, permissions) if the database has never been seeded. Safe to re-run.';

    public function handle(FirstRunService $firstRun): int
    {
        $this->info($firstRun->ensureEssentialData()
            ? 'Essential data seeded.'
            : 'Essential data already present; nothing to do.');

        return self::SUCCESS;
    }
}
