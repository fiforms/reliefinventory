<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Services\Warehouses\WarehouseContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Finding slow screens. Two sources, one view:
 *
 *  - the app log: a DB::listen hook (registered in AppServiceProvider when
 *    config('slowlog.threshold_ms') > 0) appends every slow query, with the
 *    request/command that ran it, to a JSON-lines file. Works on every
 *    platform, needs no database privileges.
 *  - MariaDB's own slow log, when the server is set up to write it to the
 *    mysql.slow_log table (the Docker stack does) and the app's database user
 *    may read it — adds rows examined/sent and lock time, and also catches
 *    queries from other clients (cron, the backup dump).
 *
 * A slow SCREEN is often not a slow query: a list that loads 10,000 rows in
 * 40 fast queries, or one that sends a 10 MB response, never trips a
 * per-query threshold. So each request is also profiled as a whole
 * (observe() counts queries, finishRequest() writes a "request" entry when the
 * request was slow, ran very many queries, or sent a very large response).
 *
 * record()/observe() run inside the query listener, so they must never issue a
 * query (that would recurse) and must never throw (a full disk can't break a page).
 */
class SlowQueryLog
{
    public function thresholdMs(): int
    {
        return max(0, (int) config('slowlog.threshold_ms'));
    }

    public function enabled(): bool
    {
        return $this->thresholdMs() > 0;
    }

    private int $requestQueries = 0;

    private float $requestDbMs = 0.0;

    /** The query listener: counts toward the current request's profile, and records the query itself if slow. */
    public function observe(QueryExecuted $query): void
    {
        $this->requestQueries++;
        $this->requestDbMs += $query->time;
        $this->record($query);
    }

    public function record(QueryExecuted $query): void
    {
        if ($query->time < $this->thresholdMs()) {
            return;
        }

        $this->append([
            't' => date('c'),
            'ms' => round($query->time, 1),
            'sql' => mb_substr($query->sql, 0, 2000),
            'source' => $this->source(),
        ]);
    }

    /** End of a web request: write a profile entry if it was slow, chatty or heavy; always reset the counters. */
    public function finishRequest(Request $request, Response $response): void
    {
        $queries = $this->requestQueries;
        $dbMs = $this->requestDbMs;
        $this->requestQueries = 0;
        $this->requestDbMs = 0.0;

        // The request's own start, not LARAVEL_START: that is process start in a long-lived worker (and in tests).
        $start = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(true));
        $ms = (microtime(true) - $start) * 1000;
        $bytes = $this->responseBytes($response);

        // The testing tools page polls itself; its own traffic is never interesting.
        if (str_starts_with($request->path(), 'json/testing/')) {
            return;
        }

        if ($ms >= (int) config('slowlog.request_ms') || $queries >= (int) config('slowlog.request_queries') || $bytes >= (int) config('slowlog.request_bytes')) {
            $this->append([
                't' => date('c'), 'kind' => 'request', 'ms' => round($ms, 1), 'db_ms' => round($dbMs, 1),
                'queries' => $queries, 'bytes' => $bytes, 'status' => $response->getStatusCode(),
                'source' => $request->method().' /'.ltrim($request->path(), '/'),
            ]);
        }
    }

    private function responseBytes(Response $response): int
    {
        if (($length = $response->headers->get('Content-Length')) !== null) {
            return (int) $length;
        }
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return 0;
        }

        return strlen((string) $response->getContent());
    }

    private function append(array $row): void
    {
        try {
            $path = config('slowlog.path');
            if (is_file($path) && filesize($path) > config('slowlog.max_bytes')) {
                @rename($path, $path.'.1');
            }

            @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // never let logging break the request
        }
    }

    private function source(): string
    {
        if (app()->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];

            return mb_substr('artisan '.implode(' ', array_slice($argv, 1, 2)), 0, 120);
        }

        $request = request();

        return $request->method().' /'.ltrim($request->path(), '/');
    }

    /**
     * Most recent first.
     *
     * @return array<int, array<string, mixed>> kind 'query' (sql) or 'request' (a whole page/endpoint)
     */
    public function appEntries(int $limit = 200): array
    {
        $path = config('slowlog.path');
        $lines = [];
        foreach ([$path.'.1', $path] as $file) { // oldest file first, so the reversal below is newest-first overall
            if (is_file($file)) {
                $lines = array_merge($lines, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            }
        }

        $entries = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            $entries[] = ['time' => $row['t'] ?? '', 'ms' => (float) ($row['ms'] ?? 0), 'sql' => $row['sql'] ?? '', 'source' => $row['source'] ?? '', 'origin' => 'app', 'kind' => $row['kind'] ?? 'query']
                + (($row['kind'] ?? null) === 'request' ? ['db_ms' => (float) ($row['db_ms'] ?? 0), 'queries' => (int) ($row['queries'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0), 'status' => (int) ($row['status'] ?? 0)] : []);
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * MariaDB's mysql.slow_log, if the server writes it to the table and this
     * user can read it. `available` false carries a `message` that says what to
     * fix — the page shows it instead of an error.
     *
     * @return array{available:bool,message:?string,entries:array<int,array<string,mixed>>}
     */
    public function dbEntries(int $limit = 200): array
    {
        // The slow log holds every warehouse's queries and is read as the instance's own user, not a warehouse's. Once
        // there are several warehouses it belongs in the instance console (MULTI_WAREHOUSE.md §13, phase 5).
        if (app(WarehouseContext::class)->multiple()) {
            return ['available' => false, 'message' => 'The database slow log covers every warehouse on this instance, so it is not shown inside one.', 'entries' => []];
        }
        try {
            $rows = DB::connection(config('warehouses.provisioning.base_connection'))->select(
                // Seconds spelled out from the parts: MariaDB's TIME_TO_SEC keeps the fraction, MySQL's drops it.
                'SELECT start_time, user_host, '
                .'HOUR(query_time) * 3600 + MINUTE(query_time) * 60 + SECOND(query_time) + MICROSECOND(query_time) / 1000000 AS secs, '
                .'HOUR(lock_time) * 3600 + MINUTE(lock_time) * 60 + SECOND(lock_time) + MICROSECOND(lock_time) / 1000000 AS lock_secs, rows_sent, rows_examined, '
                .'CONVERT(sql_text USING utf8mb4) AS sql_text FROM mysql.slow_log ORDER BY start_time DESC LIMIT '.(int) $limit
            );
        } catch (Throwable $e) {
            return ['available' => false, 'message' => $this->dbUnavailableMessage($e), 'entries' => []];
        }

        return ['available' => true, 'message' => null, 'entries' => array_map(fn ($r) => [
            'time' => (string) $r->start_time,
            'ms' => round((float) $r->secs * 1000, 1),
            'sql' => mb_substr((string) $r->sql_text, 0, 2000),
            'source' => trim((string) $r->user_host),
            'origin' => 'mariadb',
            'rows_examined' => (int) $r->rows_examined,
            'rows_sent' => (int) $r->rows_sent,
            'lock_ms' => round((float) $r->lock_secs * 1000, 1),
        ], $rows)];
    }

    private function dbUnavailableMessage(Throwable $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'command denied') || str_contains($msg, '1142')) {
            $user = config('database.connections.'.config('warehouses.provisioning.base_connection').'.username');

            return "The database user can't read MariaDB's slow log. As the database administrator run: GRANT SELECT ON mysql.slow_log TO '{$user}'@'%'; (the Docker stack does this on first start).";
        }
        if (str_contains($msg, "doesn't exist") || str_contains($msg, '1146')) {
            return 'This database server has no slow log table (it is not MariaDB/MySQL, or the log is not enabled).';
        }

        return 'MariaDB slow log unavailable: '.mb_substr($msg, 0, 200);
    }

    /**
     * Collapse literals so the same statement shape groups together:
     * "where id = 17" and "where id = 912" are one query.
     */
    public static function fingerprint(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);
        $sql = preg_replace('/\b\d+(\.\d+)?\b/', '?', $sql);
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', $sql);

        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * Group entries by statement shape: how often, how slow, how much total
     * time — the repeated offenders matter more than any single slow hit.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>> sorted by total time, worst first
     */
    public function group(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            if (($e['kind'] ?? 'query') === 'request') {
                continue;
            }
            $key = self::fingerprint($e['sql']);
            $g = &$groups[$key];
            $g ??= ['sql' => $key, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'last' => '', 'sources' => [], 'rows_examined' => 0];
            $g['count']++;
            $g['total_ms'] += $e['ms'];
            $g['max_ms'] = max($g['max_ms'], $e['ms']);
            $g['last'] = max($g['last'], $e['time']);
            $g['rows_examined'] = max($g['rows_examined'], $e['rows_examined'] ?? 0);
            $g['sources'][$e['source']] = ($g['sources'][$e['source']] ?? 0) + 1;
            unset($g);
        }

        foreach ($groups as &$g) {
            arsort($g['sources']);
            $g['sources'] = array_slice(array_keys($g['sources']), 0, 3);
            $g['avg_ms'] = round($g['total_ms'] / $g['count'], 1);
            $g['total_ms'] = round($g['total_ms'], 1);
        }
        unset($g);

        usort($groups, fn ($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        return array_values($groups);
    }

    /**
     * Whole-request profiles grouped by route: how often a screen was slow,
     * chatty or heavy, and the worst of each. Worst response time first.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    public function groupRequests(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            if (($e['kind'] ?? 'query') !== 'request') {
                continue;
            }
            $g = &$groups[$e['source']];
            $g ??= ['source' => $e['source'], 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'max_db_ms' => 0.0, 'max_queries' => 0, 'max_bytes' => 0, 'last' => ''];
            $g['count']++;
            $g['total_ms'] += $e['ms'];
            $g['max_ms'] = max($g['max_ms'], $e['ms']);
            $g['max_db_ms'] = max($g['max_db_ms'], $e['db_ms']);
            $g['max_queries'] = max($g['max_queries'], $e['queries']);
            $g['max_bytes'] = max($g['max_bytes'], $e['bytes']);
            $g['last'] = max($g['last'], $e['time']);
            unset($g);
        }
        foreach ($groups as &$g) {
            $g['avg_ms'] = round($g['total_ms'] / $g['count'], 1);
        }
        unset($g);
        usort($groups, fn ($a, $b) => $b['max_ms'] <=> $a['max_ms']);

        return array_values($groups);
    }

    /** Empties the app log (MariaDB's table is the database administrator's to truncate). */
    public function clearApp(): void
    {
        foreach ([config('slowlog.path'), config('slowlog.path').'.1'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
