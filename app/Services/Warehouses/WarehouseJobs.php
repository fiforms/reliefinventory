<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Queued jobs run in the warehouse that queued them (MULTI_WAREHOUSE.md §9). Every payload records the warehouse,
 * and the worker switches to it before the job runs and back afterwards. A job that names no warehouse, or one
 * that isn't active, fails: it is never run in the default warehouse instead. The same events fire for the `sync`
 * queue inside a request, so "back afterwards" means back to whatever was current before, not to nothing.
 */
class WarehouseJobs
{
    /** @var list<array{string, ?Warehouse}> job id => the warehouse that was current before it */
    private array $stack = [];

    public function __construct(private WarehouseContext $context) {}

    /** Register the payload hook and the worker listeners. Call before anything else listens to JobProcessing. */
    public static function register(): void
    {
        Queue::createPayloadUsing(function () {
            $context = app(WarehouseContext::class);

            return ['warehouse' => $context->has() ? $context->current()->id : null];
        });

        Event::listen(JobProcessing::class, fn (JobProcessing $e) => app(self::class)->begin($e->job));
        Event::listen(
            [JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, JobReleasedAfterException::class],
            fn ($e) => app(self::class)->end($e->job),
        );
    }

    /**
     * Drop every queued, failed and batched job that names the warehouse. The queue tables are in the control schema, shared by all
     * warehouses, so a restore (or removal) of one must clear its own and nobody else's.
     */
    public static function purge(Warehouse $warehouse): void
    {
        $connection = $warehouse->getConnection();
        foreach (['jobs', 'failed_jobs'] as $table) {
            if ($connection->getSchemaBuilder()->hasTable($table)) {
                $connection->table($table)->where('payload', 'like', '%"warehouse":"'.$warehouse->id.'"%')->delete();
            }
        }
    }

    public function begin(Job $job): void
    {
        $id = $job->payload()['warehouse'] ?? null;
        $warehouse = is_string($id) ? Warehouse::findActive($id) : null;
        if (! $warehouse) {
            $job->fail(new RuntimeException($id
                ? "The warehouse this job belongs to ({$id}) doesn't exist or isn't active."
                : 'This job names no warehouse, so it was not run.'));

            return;
        }

        $this->stack[] = [$this->key($job), $this->context->has() ? $this->context->current() : null];
        $this->context->switchTo($warehouse);
    }

    public function end(Job $job): void
    {
        $top = end($this->stack);
        if (! $top || $top[0] !== $this->key($job)) {
            return;   // already ended (a failure fires more than one event), or never began
        }
        array_pop($this->stack);
        $top[1] ? $this->context->switchTo($top[1]) : $this->context->forget();
    }

    private function key(Job $job): string
    {
        return $job->getJobId() ?: spl_object_hash($job);
    }
}
