<?php

use App\Services\SlowQueryLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->logPath = tempnam(sys_get_temp_dir(), 'slowlog');
    config(['slowlog.path' => $this->logPath, 'slowlog.threshold_ms' => 100]);
});

afterEach(function () {
    File::delete([$this->logPath, $this->logPath.'.1']);
});

function slowQuery(string $sql, float $ms): QueryExecuted
{
    return new QueryExecuted($sql, ['secret@example.com'], $ms, DB::connection());
}

test('only queries over the threshold are recorded, and never their values', function () {
    $log = app(SlowQueryLog::class);

    $log->record(slowQuery('select * from people where email = ?', 40.0));
    $log->record(slowQuery('select * from people where email = ?', 450.5));

    $entries = $log->appEntries();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['ms'])->toBe(450.5)
        ->and($entries[0]['sql'])->toBe('select * from people where email = ?')
        ->and(File::get($this->logPath))->not->toContain('secret@example.com');
});

test('recording is off unless a threshold is set', function () {
    config(['slowlog.threshold_ms' => 0]);

    expect(app(SlowQueryLog::class)->enabled())->toBeFalse();
});

test('the log rotates when it gets big, keeping one older file', function () {
    config(['slowlog.max_bytes' => 200]);
    $log = app(SlowQueryLog::class);

    foreach (range(1, 6) as $i) {
        $log->record(slowQuery('select '.str_repeat('x', 60).' from t'.$i, 500));
    }

    $entries = $log->appEntries();
    expect(File::exists($this->logPath.'.1'))->toBeTrue()
        ->and($entries)->not->toBeEmpty()
        ->and(count($entries))->toBeLessThan(6) // older rotations are dropped
        ->and($entries[0]['sql'])->toEndWith('from t6'); // newest first
});

test('the same statement with different values groups together, worst total time first', function () {
    $log = app(SlowQueryLog::class);
    foreach ([17, 912, 4] as $id) {
        $log->record(slowQuery("select * from orderlines where orderdonation_id = {$id} and note = 'a{$id}'", 300));
    }
    $log->record(slowQuery('select count(*) from item_ledgers where id in (1, 2, 3)', 700));

    $groups = $log->group($log->appEntries());

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['sql'])->toBe('select * from orderlines where orderdonation_id = ? and note = ?')
        ->and($groups[0]['count'])->toBe(3)
        ->and($groups[0]['total_ms'])->toBe(900.0)
        ->and($groups[1]['sql'])->toBe('select count(*) from item_ledgers where id in (?+)');
});

test('an unreadable MariaDB slow log is reported as a fixable message, not an error', function () {
    $result = app(SlowQueryLog::class)->dbEntries();

    // Depends on how the test database user is set up: either it can read the table or it gets the explanation.
    expect($result['available'] || is_string($result['message']))->toBeTrue()
        ->and($result['entries'])->toBeArray();
});

test('the testing tools endpoints list and clear the app log', function () {
    $this->actingAs(userWithPermissions('admin-system'));
    app(SlowQueryLog::class)->record(slowQuery('select 1 from slow_thing', 900));

    $this->getJson('/json/testing/slow-queries?source=app')->assertOk()
        ->assertJsonPath('entries.0.sql', 'select 1 from slow_thing')
        ->assertJsonPath('groups.0.count', 1);

    $this->deleteJson('/json/testing/slow-queries')->assertOk();
    $this->getJson('/json/testing/slow-queries?source=app')->assertJsonCount(0, 'entries');
});

test('slowlog:show prints grouped results', function () {
    app(SlowQueryLog::class)->record(slowQuery('select * from big_table where id = 5', 800));

    $this->artisan('slowlog:show', ['--source' => 'app'])->expectsOutputToContain('big_table')->assertSuccessful();
});

test('a heavy or chatty request is profiled even though no single query was slow', function () {
    config(['slowlog.request_bytes' => 1000, 'slowlog.request_queries' => 5, 'slowlog.request_ms' => 60000]);
    $log = app(SlowQueryLog::class);

    // Heavy: one fast query, a big response.
    $log->observe(slowQuery('select * from pallets', 3));
    $log->finishRequest(Request::create('/json/pallets'), new Response(str_repeat('x', 5000)));

    // Chatty: lots of fast queries, a tiny response.
    foreach (range(1, 8) as $i) {
        $log->observe(slowQuery('select * from people where id = ?', 2));
    }
    $log->finishRequest(Request::create('/json/orders'), new Response('ok'));

    // Neither: not recorded, and the counters were reset between requests.
    $log->observe(slowQuery('select 1', 1));
    $log->finishRequest(Request::create('/json/units'), new Response('ok'));

    $groups = $log->groupRequests($log->appEntries());
    $bySource = collect($groups)->keyBy('source');

    expect($groups)->toHaveCount(2)
        ->and($bySource['GET /json/pallets']['max_bytes'])->toBe(5000)
        ->and($bySource['GET /json/pallets']['max_queries'])->toBe(1)
        ->and($bySource['GET /json/orders']['max_queries'])->toBe(8)
        ->and($log->group($log->appEntries()))->toBe([]); // none of those queries was individually slow
});

test('slowlog:show --requests lists them', function () {
    config(['slowlog.request_bytes' => 10]);
    app(SlowQueryLog::class)->finishRequest(Request::create('/json/palletstatus'), new Response(str_repeat('x', 4000)));

    $this->artisan('slowlog:show', ['--requests' => true, '--source' => 'app'])->expectsOutputToContain('/json/palletstatus')->assertSuccessful();
});
