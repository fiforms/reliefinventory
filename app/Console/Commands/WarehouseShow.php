<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Membership;
use App\Services\FirstRunService;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Everything the instance knows about one warehouse, including who its Administrators are (read from inside it). */
class WarehouseShow extends Command
{
    use InstanceCommand;

    protected $signature = 'warehouse:show {warehouse : the warehouse id}';

    protected $description = 'Show one warehouse: status, schema, address, key, setup code, hostnames and its Administrators.';

    public function handle(WarehouseContext $context, SetupCodes $codes): int
    {
        if (! $warehouse = $this->warehouseOrNull($this->argument('warehouse'))) {
            return self::FAILURE;
        }

        $rows = [
            'Name' => $warehouse->display_name.($warehouse->is_default ? '  (default)' : '').($warehouse->is_training ? '  (training)' : ''),
            'Id' => $warehouse->id,
            'Status' => $warehouse->status.($warehouse->archived_at ? " (archived {$warehouse->archived_at->toDateString()}, was {$warehouse->status_before_archive})" : ''),
            'Uuid' => $warehouse->uuid,
            'Schema' => $warehouse->schema_name,
            'Migrations' => $warehouse->migration_state,
            'Address' => $warehouse->setupUrl() ? preg_replace('#/activate$#', '', $warehouse->setupUrl()) : '',
            'Hostnames' => $warehouse->domains()->pluck('hostname')->implode(', ') ?: '(none: reached by the shared address)',
            'Listed in chooser' => $warehouse->listed ? 'yes' : 'no',
            'Contact' => $warehouse->admin_email ?: '',
            'Key file' => $warehouse->hasKey() ? 'present' : 'MISSING',
            'Files folder' => is_dir($warehouse->storagePath()) ? $warehouse->storagePath() : '(none yet)',
            'Notes' => $warehouse->notes ?: '',
        ];
        try {
            if ($pending = $codes->pending($warehouse)) {
                $rows['Setup code'] = "waiting, valid until {$pending->expires_at}";
            }
        } catch (Throwable) {
            $rows['Setup code'] = '(the control schema is behind this software: `php artisan instance:migrate`)';
        }
        foreach ($rows as $label => $value) {
            $this->line(str_pad($label.':', 20).$value);
        }

        if (in_array($warehouse->status, ['provisioning', 'awaiting_setup'], true)) {
            return self::SUCCESS;
        }
        try {
            $admins = $context->run($warehouse, fn () => DB::table('people_roles')
                ->join('people', 'people.id', '=', 'people_roles.person_id')
                ->where('people_roles.role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)
                ->get(['people.id', 'people.first_name', 'people.last_name', 'people.email', 'people.account_id']));
        } catch (Throwable $e) {
            $this->components->warn('Could not read its database: '.$e->getMessage());

            return self::SUCCESS;
        }
        $members = Membership::where('warehouse_id', $warehouse->id)->count();
        $this->newLine();
        $this->line("Administrators ({$members} login(s) belong to this warehouse):");
        $this->table(['Person', 'Name', 'Email', 'Login'], $admins->map(fn ($a) => [$a->id, trim("{$a->first_name} {$a->last_name}"), $a->email, $a->account_id ? 'yes' : 'no']));

        return self::SUCCESS;
    }
}
