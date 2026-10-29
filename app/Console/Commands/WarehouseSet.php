<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use Illuminate\Console\Command;

/** Rename a warehouse, change its contact, show it in (or hide it from) the login page's chooser, or keep a note on it. */
class WarehouseSet extends Command
{
    use InstanceCommand;

    protected $signature = 'warehouse:set {warehouse : the warehouse id}
        {--name= : New display name (unique on the instance: the login page and the chooser show it)}
        {--email= : Contact email for whoever runs it}
        {--listed= : yes or no: whether the chooser on the shared address lists it}
        {--notes= : A note for the instance admin (empty to clear)}';

    protected $description = 'Change a warehouse\'s name, contact, chooser listing or notes.';

    public function handle(): int
    {
        if (! $warehouse = $this->warehouseOrNull($this->argument('warehouse'))) {
            return self::FAILURE;
        }

        $changes = [];
        if (($name = $this->option('name')) !== null) {
            $name = trim($name);
            if ($name === '' || Warehouse::where('display_name', $name)->whereKeyNot($warehouse->id)->exists()) {
                $this->components->error('A warehouse needs a name, and no other warehouse may have the same one.');

                return self::FAILURE;
            }
            $changes['display_name'] = $name;
        }
        if (($email = $this->option('email')) !== null) {
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->components->error('That is not an email address.');

                return self::FAILURE;
            }
            $changes['admin_email'] = $email !== '' ? $email : null;
        }
        if (($listed = $this->option('listed')) !== null) {
            if (! in_array(strtolower($listed), ['yes', 'no'], true)) {
                $this->components->error('--listed is yes or no.');

                return self::FAILURE;
            }
            $changes['listed'] = strtolower($listed) === 'yes';
        }
        if (($notes = $this->option('notes')) !== null) {
            $changes['notes'] = $notes !== '' ? $notes : null;
        }
        if (! $changes) {
            $this->components->error('Nothing to change: give --name, --email, --listed or --notes.');

            return self::FAILURE;
        }

        $before = collect($changes)->map(fn ($v, $k) => $warehouse->{$k})->all();
        $warehouse->forceFill($changes)->save();
        InstanceAuditLog::record('warehouse.updated', $warehouse, ['changed' => array_keys($changes), 'was' => array_filter($before, fn ($v) => $v !== null)]);
        $this->components->info("Updated {$warehouse->display_name}: ".implode(', ', array_keys($changes)).'.');

        return self::SUCCESS;
    }
}
