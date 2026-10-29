<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Every artisan command takes `--warehouse=<id>` (MULTI_WAREHOUSE.md §13). With one warehouse a command runs in it
 * without being told (WarehouseContext::bootConsole()); with several, a command that works on a warehouse's data
 * refuses to start until it is told which — there is no default, so `setup:reset`, the simulator or a restore can
 * never land on the wrong one. Commands that are about the instance itself are listed below and run in none.
 */
class WarehouseConsole
{
    /** Commands (or `prefix:` families) that never work on one warehouse's data. */
    private const INSTANCE_COMMANDS = [
        'instance:', 'provisioner:', 'warehouse:', 'account:', 'queue:', 'schedule:', 'slowlog:', 'config:', 'route:', 'view:', 'event:',
        'optimize', 'reverb:', 'package:discover', 'vendor:publish', 'key:generate', 'list', 'help', 'about', 'env',
        'completion', '_complete', 'inspire', 'test', 'pail', 'serve', 'make:', 'pest:', 'ziggy:', 'up', 'down', 'storage:',
        'lang:', 'stub:', 'docs', 'inertia:', 'sail:', 'install:', 'reload',
    ];

    public function __construct(private WarehouseContext $context) {}

    public static function register(): void
    {
        Event::listen(ArtisanStarting::class, fn (ArtisanStarting $e) => $e->artisan->getDefinition()->addOption(new InputOption(
            'warehouse', null, InputOption::VALUE_REQUIRED, 'The warehouse (its id, or "default") to run in; needed once the instance has several',
        )));
        Event::listen(CommandStarting::class, fn (CommandStarting $e) => app(self::class)->starting((string) $e->command, $e->input->getParameterOption('--warehouse', null, true)));
    }

    public function starting(string $command, mixed $warehouseId): void
    {
        // `--warehouse=default` is for scripts (the Docker entrypoint) that may run before the control schema exists:
        // then there is only the one schema anyway, and the command runs as it always did.
        if ($warehouseId === 'default') {
            try {
                $this->context->switchTo($this->context->defaultWarehouse());
            } catch (\Throwable) {
            }

            return;
        }
        if (is_string($warehouseId) && $warehouseId !== '') {
            $warehouse = Warehouse::find(strtoupper($warehouseId))
                ?? throw new RuntimeException("There is no warehouse {$warehouseId}. `php artisan warehouse:list` shows them.");
            $this->context->switchTo($warehouse);

            return;
        }

        if ($this->context->has() || self::isInstanceCommand($command)) {
            return;
        }
        try {
            $several = Warehouse::count() > 1;
        } catch (\Throwable) {
            return;   // no control schema yet: instance:migrate creates it
        }
        if ($several) {
            throw new RuntimeException("This instance has several warehouses: say which one `{$command}` is for with --warehouse=<id> (`php artisan warehouse:list` shows them).");
        }
    }

    public static function isInstanceCommand(string $command): bool
    {
        return $command === '' || collect(self::INSTANCE_COMMANDS)->contains(
            fn ($entry) => str_ends_with($entry, ':') ? Str::startsWith($command, $entry) : $command === $entry,
        );
    }
}
