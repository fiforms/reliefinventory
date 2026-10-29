<?php

use App\Models\Category;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseConsole;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseJobs;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md §9 and §13: queued jobs and commands run in the right warehouse, and never fall back to the
// default one.

class RecordWarehouseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public static array $ranIn = [];

    public function handle(): void
    {
        self::$ranIn[] = [app(WarehouseContext::class)->current()->id, Category::where('name', 'Marker')->exists()];
    }
}

beforeEach(function () {
    RecordWarehouseJob::$ranIn = [];
    $this->other = SecondWarehouse::make($this);
});

afterEach(fn () => app(WarehouseContext::class)->switchTo(Warehouse::default()));

test('a queued job runs in the warehouse that queued it, and the caller is put back afterwards', function () {
    $context = app(WarehouseContext::class);
    $context->run($this->other, function () {
        Category::create(['name' => 'Marker']);
        RecordWarehouseJob::dispatch();   // the sync queue fires the same worker events
    });
    RecordWarehouseJob::dispatch();

    expect(RecordWarehouseJob::$ranIn)->toBe([[$this->other->id, true], [Warehouse::default()->id, false]])
        ->and($context->current()->is(Warehouse::default()))->toBeTrue();
});

test('every payload names its warehouse', function () {
    $payload = (fn () => $this->createPayloadArray(new RecordWarehouseJob, 'default'))->call(app('queue')->connection('database'));
    expect($payload['warehouse'])->toBe(Warehouse::default()->id);
});

test('a job that names no warehouse, or one that is not active, fails instead of running in the default one', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['warehouse' => null]);
    $job->shouldReceive('fail')->once();
    app(WarehouseJobs::class)->begin($job);

    $this->other->forceFill(['status' => 'suspended'])->save();
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['warehouse' => $this->other->id]);
    $job->shouldReceive('fail')->once();
    app(WarehouseJobs::class)->begin($job);
});

test('with several warehouses a warehouse command needs --warehouse; instance commands do not', function () {
    $console = app(WarehouseConsole::class);
    $context = app(WarehouseContext::class);
    $context->forget();

    expect(fn () => $console->starting('setup:reset', null))->toThrow(RuntimeException::class, '--warehouse')
        ->and(fn () => $console->starting('migrate', null))->toThrow(RuntimeException::class)
        ->and(fn () => $console->starting('setup:reset', 'ZZZZZZZZ'))->toThrow(RuntimeException::class, 'no warehouse');

    $console->starting('queue:work', null);
    $console->starting('instance:migrate', null);
    expect($context->has())->toBeFalse();

    $console->starting('setup:status', strtolower($this->other->id));
    expect($context->current()->is($this->other))->toBeTrue();
});

test('warehouse:each runs a command once in every active warehouse', function () {
    $this->artisan('warehouse:each', ['line' => 'setup:status'])
        ->expectsOutputToContain(Warehouse::default()->id)
        ->expectsOutputToContain($this->other->id)
        ->assertSuccessful();
    $this->artisan('warehouse:each', ['line' => 'setup:status --warehouse=X'])->assertFailed();
});
