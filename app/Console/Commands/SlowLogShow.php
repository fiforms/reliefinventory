<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\SlowQueryLog;
use Illuminate\Console\Command;

class SlowLogShow extends Command
{
    protected $signature = 'slowlog:show
        {--source=all : app, db (MariaDB slow log) or all}
        {--recent : List individual queries, newest first, instead of grouping by statement}
        {--requests : Show slow, chatty or heavy pages/endpoints (whole requests) instead of individual queries}
        {--limit=15 : How many rows to show}
        {--clear : Empty the app slow-query log after showing it}';

    protected $description = 'Show the slowest database queries (grouped by statement shape, worst total time first) from the app log and MariaDB\'s slow log; --requests shows whole slow/heavy pages instead.';

    public function handle(SlowQueryLog $log): int
    {
        $source = $this->option('source');
        $limit = max(1, (int) $this->option('limit'));
        $entries = [];

        if (! $log->enabled()) {
            $this->warn('App-side logging is off. Set RI_SLOW_QUERY_MS=250 (milliseconds) in .env to start recording; the Docker stack does this already.');
        }

        if (in_array($source, ['all', 'app'], true)) {
            $entries = array_merge($entries, $log->appEntries(5000));
        }
        if (in_array($source, ['all', 'db'], true)) {
            $db = $log->dbEntries(5000);
            if ($db['available']) {
                $entries = array_merge($entries, $db['entries']);
            } elseif ($source === 'db' || $log->enabled()) {
                $this->line('MariaDB slow log: '.$db['message']);
            }
        }

        if ($this->option('requests')) {
            $groups = $log->groupRequests($entries);
            if (! $groups) {
                $this->info('No slow, chatty or heavy requests recorded.');

                return self::SUCCESS;
            }
            $this->table(['Worst', 'Avg', 'Count', 'DB time', 'Queries', 'Response', 'Request'], array_map(fn ($g) => [
                round($g['max_ms']).' ms', round($g['avg_ms']).' ms', $g['count'], round($g['max_db_ms']).' ms', $g['max_queries'], number_format($g['max_bytes'] / 1024).' KB', $g['source'],
            ], array_slice($groups, 0, $limit)));

            return self::SUCCESS;
        }

        if (! array_filter($entries, fn ($e) => ($e['kind'] ?? 'query') === 'query')) {
            $this->info('No slow queries recorded.');

            return self::SUCCESS;
        }

        $entries = array_values(array_filter($entries, fn ($e) => ($e['kind'] ?? 'query') === 'query'));

        if ($this->option('recent')) {
            usort($entries, fn ($a, $b) => strcmp($b['time'], $a['time']));
            $this->table(['When', 'ms', 'From', 'Where', 'Query'], array_map(fn ($e) => [
                substr($e['time'], 0, 19), $e['ms'], $e['origin'], $e['source'], mb_substr(preg_replace('/\s+/', ' ', $e['sql']), 0, 90),
            ], array_slice($entries, 0, $limit)));
        } else {
            $this->table(['Total ms', 'Count', 'Avg ms', 'Max ms', 'Worst caller', 'Query'], array_map(fn ($g) => [
                $g['total_ms'], $g['count'], $g['avg_ms'], $g['max_ms'], $g['sources'][0] ?? '', mb_substr($g['sql'], 0, 90),
            ], array_slice($log->group($entries), 0, $limit)));
        }

        if ($this->option('clear')) {
            $log->clearApp();
            $this->info('App slow-query log cleared.');
        }

        return self::SUCCESS;
    }
}
