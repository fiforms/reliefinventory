<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
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
    public const TASKS = [SwitchDatabase::class];

    private ?Warehouse $current = null;

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

    public function switchTo(Warehouse $warehouse): void
    {
        if ($this->current?->is($warehouse)) {
            return;
        }

        foreach ($this->tasks() as $task) {
            $task->makeCurrent($warehouse);
        }
        $this->current = $warehouse;
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
