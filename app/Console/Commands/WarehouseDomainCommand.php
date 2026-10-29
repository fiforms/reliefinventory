<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Services\Warehouses\WarehouseDomains;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Map hostnames to warehouses (MULTI_WAREHOUSE.md §3): `warehouse:domain list`, `add <id> <hostname> [--primary]`, `remove <id> <hostname>`,
 * `primary <id> <hostname>`. DNS and TLS for the hostname have to point at this instance first; the instance console (phase 5) will
 * do this from the browser.
 */
class WarehouseDomainCommand extends Command
{
    protected $signature = 'warehouse:domain {action : list, add, remove or primary} {warehouse? : the warehouse id} {hostname? : the hostname} {--primary : make an added hostname the primary one}';

    protected $description = 'Map hostnames to warehouses (a mapped host reaches one warehouse and ignores the chooser).';

    public function handle(WarehouseDomains $domains): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            return $this->list($domains);
        }
        if (! in_array($action, ['add', 'remove', 'primary'], true) || ! $this->argument('warehouse') || ! $this->argument('hostname')) {
            $this->components->error('Usage: warehouse:domain list | add <id> <hostname> [--primary] | remove <id> <hostname> | primary <id> <hostname>');

            return self::FAILURE;
        }

        $warehouse = Warehouse::find(strtoupper((string) $this->argument('warehouse')));
        if (! $warehouse) {
            $this->components->error("There is no warehouse {$this->argument('warehouse')}. `php artisan warehouse:list` shows them.");

            return self::FAILURE;
        }

        try {
            if ($action === 'add') {
                $domain = $domains->add($warehouse, (string) $this->argument('hostname'), (bool) $this->option('primary'));
                $this->components->info("{$domain->hostname} now reaches {$warehouse->display_name} ({$warehouse->id}).".($domain->is_primary ? ' It is the primary address.' : ''));
                $this->line('  Point DNS and TLS for it at this instance; the app cannot do that for you.');
                if ($note = $domains->turnstileNote($domain)) {
                    $this->components->warn($note);
                }
            } elseif ($action === 'remove') {
                $domains->remove($warehouse, (string) $this->argument('hostname'));
                $this->components->info("{$this->argument('hostname')} no longer reaches {$warehouse->display_name}.");
            } else {
                $domain = $warehouse->domains()->where('hostname', strtolower((string) $this->argument('hostname')))->first()
                    ?? throw new InvalidArgumentException("{$this->argument('hostname')} is not mapped to this warehouse.");
                $domains->makePrimary($domain);
                $this->components->info("{$domain->hostname} is now the primary address of {$warehouse->display_name}.");
            }
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function list(WarehouseDomains $domains): int
    {
        $rows = WarehouseDomain::with('warehouse')->orderBy('warehouse_id')->orderByDesc('is_primary')->get()->map(fn (WarehouseDomain $d) => [
            $d->hostname, $d->warehouse_id, $d->warehouse?->display_name, $d->is_primary ? 'primary' : '',
            $d->turnstile_confirmed_at ? 'confirmed' : ($domains->turnstileNote($d) ? 'NOT confirmed' : ''),
        ]);
        $this->table(['Hostname', 'Warehouse', 'Name', '', 'Turnstile'], $rows);

        return self::SUCCESS;
    }
}
