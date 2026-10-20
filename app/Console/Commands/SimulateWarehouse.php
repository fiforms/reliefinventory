<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\FirstRunService;
use App\Services\Simulator\SimulationRun;
use App\Services\Simulator\WarehouseSimulator;
use Illuminate\Console\Command;
use Throwable;

class SimulateWarehouse extends Command
{
    protected $signature = 'simulate:warehouse
        {--days=365 : How many days back from today to start}
        {--orders=1200 : Approximate total orders over the period}
        {--errors=4 : How many negative-stock over-picks to inject on purpose}
        {--seed= : Seed for reproducible runs (the same seed gives the same data)}
        {--background : Run detached and return immediately (progress: simulate:status)}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Fill a brand-new instance with a simulated year of warehouse activity (donations, sorting, requests, shipping) for testing. Refuses to run on an instance that has data.';

    public function handle(FirstRunService $firstRun, SimulationRun $runner): int
    {
        $days = (int) $this->option('days');
        $orders = (int) $this->option('orders');
        $errors = (int) $this->option('errors');
        $seed = $this->option('seed') !== null && $this->option('seed') !== '' ? (int) $this->option('seed') : null;

        if ($days < 7 || $days > 1500 || $orders < 1 || $errors < 0) {
            $this->error('Use --days between 7 and 1500, --orders of at least 1 and --errors of 0 or more.');

            return self::INVALID;
        }

        // Seeding the catalog is safe (it only runs on a database that has never been seeded) and makes this
        // usable straight after `php artisan migrate`, but the simulator still needs an administrator to exist.
        try {
            $firstRun->ensureEssentialData();
            $reason = WarehouseSimulator::blockedReason() ?? WarehouseSimulator::missingRequirement();
        } catch (Throwable $e) {
            $runner->markStartRefused($e->getMessage());

            throw $e;
        }

        if ($reason) {
            $this->error($reason);
            $runner->markStartRefused($reason);

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm("This fills the instance with about {$days} days of simulated data and can't be undone. Continue?")) {
            return self::FAILURE;
        }

        if ($this->option('background')) {
            if (! $runner->start($days, $orders, $seed, $errors)) {
                $this->error('A simulation is already running.');

                return self::FAILURE;
            }
            $this->info('Simulation started in the background. Check progress with: php artisan simulate:status');

            return self::SUCCESS;
        }

        $bar = null;
        try {
            $summary = $runner->execute($days, $orders, $seed, $errors, function (int $day, int $total, string $message) use (&$bar) {
                if ($this->output->isQuiet()) {
                    return;
                }
                $bar ??= tap($this->output->createProgressBar($total), fn ($b) => $b->setFormat(' %current%/%max% days [%bar%] %percent:3s%%  %message%'));
                $bar->setMessage($message);
                $bar->setProgress($day);
            });
        } catch (Throwable $e) {
            $bar?->clear();
            $this->error('Simulation failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }
        $bar?->finish();
        $this->newLine(2);

        $this->report($summary);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $s */
    private function report(array $s): void
    {
        $this->info("Simulated {$s['days']} days ({$s['from']} to {$s['to']}) in {$s['seconds']}s — seed {$s['seed']}");
        $this->table(['Records', 'Count'], collect($s['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), number_format($n)])->values()->all());
        $this->line('Donations: '.$this->inline($s['donation_statuses']));
        $this->line('Orders:    '.$this->inline($s['order_statuses']));
        $this->line("Requested units actually filled: {$s['fill_rate_percent']}%");

        if ($s['injected_errors']) {
            $this->newLine();
            $this->warn('Negative-stock errors injected on purpose (to see how the app handles them):');
            $this->table(['Date', 'Order', 'Item', 'On hand', 'Picked', 'Left'], array_map(fn ($e) => [$e['date'], '#'.$e['order_id'], $e['item'], $e['on_hand_before'], $e['picked'], $e['on_hand_after']], $s['injected_errors']));
        }
        if ($s['items_negative_now']) {
            $this->warn('Still negative at the end of the run: '.collect($s['items_negative_now'])->map(fn ($i) => "{$i['item']} ({$i['on_hand']})")->implode(', '));
        }
    }

    private function inline(array $counts): string
    {
        return collect($counts)->map(fn ($n, $k) => "{$k} {$n}")->implode(', ');
    }
}
