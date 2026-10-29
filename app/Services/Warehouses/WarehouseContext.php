<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Which warehouse this request, job or command is working in (MULTI_WAREHOUSE.md §8). Switching runs each
 * SwitchTask; nothing else in the app should re-point connections, keys or storage itself.
 *
 * A singleton: one per PHP request, job or command.
 */
class WarehouseContext
{
    /** @var list<class-string<SwitchTask>> */
    public const TASKS = [SwitchDatabase::class, SwitchKey::class, SwitchStorage::class, SwitchCache::class, SwitchLogContext::class, SwitchUrlRoot::class];

    /** Names the browser's chosen warehouse (MULTI_WAREHOUSE.md §3). Not encrypted, read before EncryptCookies. */
    public const SELECTOR_COOKIE = 'ri_warehouse';

    private ?Warehouse $current = null;

    private ?bool $multiple = null;

    /** @var list<SwitchTask>|null */
    private ?array $tasks = null;

    public function current(): Warehouse
    {
        // Commands (and migrations, and tests) run outside any request. While the instance has exactly one
        // warehouse there is nothing to choose; with more, a command must name one (MULTI_WAREHOUSE.md §13).
        if (! $this->current && app()->runningInConsole() && Warehouse::count() === 1) {
            $this->switchTo($this->defaultWarehouse());
        }

        return $this->current ?? throw new RuntimeException('No warehouse is selected for this request, job or command.');
    }

    public function has(): bool
    {
        return $this->current !== null;
    }

    /** Whether the instance hosts more than one warehouse: the tab guard and selector links only matter then. */
    public function multiple(): bool
    {
        return $this->multiple ??= Warehouse::count() > 1;
    }

    /**
     * The name of a long-lived cookie that belongs to one warehouse (PIN device trust, device sessions, design-vote
     * ballots): warehouses on one host share the browser's cookies, so each gets its own (§3). The default warehouse
     * keeps the plain name, so browsers approved before there were several warehouses stay approved.
     */
    public function cookieName(string $base): string
    {
        $warehouse = $this->current();

        return $warehouse->is_default ? $base : $base.'_'.strtolower($warehouse->id);
    }

    public function switchTo(Warehouse $warehouse): void
    {
        if ($this->current?->is($warehouse)) {
            return;
        }

        // All or nothing: a task that fails (a missing key file) must not leave the others pointing at it, so
        // the caller is put back in the warehouse it was in.
        $previous = $this->current;
        $this->forget();
        $applied = [];
        try {
            foreach ($this->tasks() as $task) {
                $task->makeCurrent($warehouse);
                $applied[] = $task;
            }
            $this->multiple = null;
        } catch (\Throwable $e) {
            foreach (array_reverse($applied) as $task) {
                $task->forgetCurrent();
            }
            if ($previous) {
                $this->switchTo($previous);
            }
            throw $e;
        }
        $this->current = $warehouse;
    }

    /** Switch to the same warehouse again from scratch, for when something it is switched with (its key) was changed on disk. */
    public function refresh(): void
    {
        if ($this->current) {
            $warehouse = $this->current->fresh() ?? $this->current;
            $this->forget();
            $this->switchTo($warehouse);
        }
    }

    public function forget(): void
    {
        if (! $this->current) {
            return;
        }

        foreach (array_reverse($this->tasks()) as $task) {
            $task->forgetCurrent();
        }
        $this->current = null;
    }

    /**
     * Console commands (and the queue worker) start in the sole warehouse, so they use its connection, key and
     * files without each one asking. Quietly does nothing before `instance:migrate` has set the instance up, or
     * when there is more than one warehouse (a command then has to choose).
     */
    public function bootConsole(): void
    {
        try {
            if (! $this->current && Warehouse::count() === 1) {
                $this->switchTo($this->defaultWarehouse());
            }
        } catch (\Throwable) {
            // no control schema or key yet: instance:migrate creates them
        }
    }

    /** Run $callback in $warehouse, then return to whatever was current before. */
    public function run(Warehouse $warehouse, callable $callback): mixed
    {
        $previous = $this->current;
        $this->switchTo($warehouse);

        try {
            return $callback($warehouse);
        } finally {
            $previous ? $this->switchTo($previous) : $this->forget();
        }
    }

    /** The default warehouse, with a hint when the control schema was never set up. */
    public function defaultWarehouse(): Warehouse
    {
        try {
            $warehouse = Warehouse::default();
        } catch (QueryException $e) {
            throw new RuntimeException('The control schema is not set up. Run `php artisan instance:migrate`.', 0, $e);
        }

        return $warehouse ?? throw new RuntimeException('No default warehouse is registered. Run `php artisan instance:migrate`.');
    }

    /** @return list<SwitchTask> */
    private function tasks(): array
    {
        return $this->tasks ??= array_map(fn (string $class) => app($class), self::TASKS);
    }
}
