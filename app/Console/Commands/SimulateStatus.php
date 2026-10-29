<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\Simulator\SimulationRun;
use Illuminate\Console\Command;

class SimulateStatus extends Command
{
    protected $signature = 'simulate:status';

    protected $description = 'Show the progress or result of the last warehouse simulation run.';

    public function handle(SimulationRun $runner): int
    {
        $s = $runner->status();

        match ($s['state']) {
            'none' => $this->line('No simulation has been run.'),
            'running' => $this->info(sprintf('Running: day %d of %d (%s)', $s['day'] ?? 0, $s['total'] ?? 0, $s['message'] ?? '')),
            'done' => $this->info('Finished. '.collect($s['summary']['counts'] ?? [])->only(['donations', 'orders', 'ledger_rows', 'pallets'])->map(fn ($n, $k) => number_format($n).' '.str_replace('_', ' ', $k))->implode(', ')),
            default => $this->error('Failed: '.($s['error'] ?? 'unknown error')),
        };

        return ($s['state'] ?? '') === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
