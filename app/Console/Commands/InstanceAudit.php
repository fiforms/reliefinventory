<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;

/** The instance audit log (what instance-level commands and the instance admin did), newest last. No page shows it until the instance console exists. */
class InstanceAudit extends Command
{
    protected $signature = 'instance:audit {--limit=30 : How many entries} {--for= : Only entries about this warehouse id} {--action= : Only actions that start with this (e.g. account, warehouse.removed)}';

    protected $description = 'Show the instance audit log.';

    public function handle(): int
    {
        $rows = InstanceAuditLog::orderByDesc('id')
            ->when($this->option('for'), fn ($q, $id) => $q->where('warehouse_id', strtoupper($id)))
            ->when($this->option('action'), fn ($q, $prefix) => $q->where('action', 'like', addcslashes($prefix, '%_\\').'%'))
            ->limit(max(1, (int) $this->option('limit')))->get()->reverse();

        $this->table(['When', 'Who', 'Action', 'Warehouse', 'Details'], $rows->map(fn (InstanceAuditLog $r) => [
            $r->created_at?->format('Y-m-d H:i'), $r->actor, $r->action, $r->warehouse_id ? "{$r->warehouse_id} {$r->subject}" : '',
            $r->details ? json_encode($r->details, JSON_UNESCAPED_SLASHES) : '',
        ]));

        return self::SUCCESS;
    }
}
