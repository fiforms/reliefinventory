<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The warehouse operations the Instance Admin screen offers, with the same rules as the `warehouse:suspend`, `activate`,
 * `resume`, `set` and `setup-code` commands (archive/remove/create/domains are WarehouseRemoval, WarehouseCreator and
 * WarehouseDomains). Every method throws RuntimeException with a sentence fit to show the instance admin.
 */
class WarehouseManager
{
    /** The shortest time a warehouse stays archived before the screen will remove it; `warehouse:remove` has no such wait. */
    public const REMOVAL_WAIT_DAYS = 30;

    public function __construct(private SetupCodes $codes) {}

    public function suspend(Warehouse $warehouse, bool $maintenance, ?string $reason, string $by): void
    {
        $to = $maintenance ? 'maintenance' : 'suspended';
        if (! in_array($warehouse->status, ['active', 'suspended', 'maintenance'], true)) {
            throw new RuntimeException("{$warehouse->display_name} is {$warehouse->status}; only an active (or already suspended) warehouse can be taken out of service this way.");
        }
        if ($warehouse->status === $to) {
            throw new RuntimeException("{$warehouse->display_name} is already {$to}.");
        }
        $was = $warehouse->status;
        $warehouse->forceFill(['status' => $to])->save();
        InstanceAuditLog::record('warehouse.suspended', $warehouse, array_filter(['was' => $was, 'now' => $to, 'reason' => $reason]), $by);
    }

    public function activate(Warehouse $warehouse, string $by): void
    {
        if ($warehouse->status === 'active') {
            throw new RuntimeException("{$warehouse->display_name} is already active.");
        }
        if (! in_array($warehouse->status, ['suspended', 'maintenance'], true)) {
            throw new RuntimeException("{$warehouse->display_name} is {$warehouse->status}, which this button doesn't change.");
        }
        $was = $warehouse->status;
        $warehouse->forceFill(['status' => 'active'])->save();
        InstanceAuditLog::record('warehouse.activated', $warehouse, ['was' => $was], $by);
    }

    /** End a restore that left the warehouse out of service (check the restore log first). */
    public function resume(Warehouse $warehouse, string $by): void
    {
        if ($warehouse->status !== 'restoring') {
            throw new RuntimeException("{$warehouse->display_name} is {$warehouse->status}, not restoring; there is nothing to resume.");
        }
        $warehouse->forceFill(['status' => 'active'])->save();
        InstanceAuditLog::record('warehouse.resumed', $warehouse, [], $by);
    }

    /** @param array{display_name?: string, admin_email?: ?string, listed?: bool, notes?: ?string} $changes */
    public function update(Warehouse $warehouse, array $changes, string $by): void
    {
        if (isset($changes['display_name'])) {
            $name = trim($changes['display_name']);
            if ($name === '' || Warehouse::where('display_name', $name)->whereKeyNot($warehouse->id)->exists()) {
                throw new RuntimeException('A warehouse needs a name, and no other warehouse may have the same one.');
            }
            $changes['display_name'] = $name;
        }
        if (! $changes) {
            throw new RuntimeException('Nothing to change.');
        }
        $before = collect($changes)->map(fn ($v, $k) => $warehouse->{$k})->all();
        $warehouse->forceFill($changes)->save();
        InstanceAuditLog::record('warehouse.updated', $warehouse, ['changed' => array_keys($changes), 'was' => array_filter($before, fn ($v) => $v !== null)], $by);
    }

    /** @return string the new code, shown once */
    public function issueSetupCode(Warehouse $warehouse, string $by): string
    {
        if (! $warehouse->isAwaitingSetup()) {
            throw new RuntimeException("{$warehouse->display_name} is {$warehouse->status}; only a warehouse awaiting setup has a setup code.");
        }
        [$code] = $this->codes->issue($warehouse, $by);
        InstanceAuditLog::record('warehouse.setup_code_issued', $warehouse, [], $by);

        return $code;
    }

    public function revokeSetupCode(Warehouse $warehouse, string $by): int
    {
        $count = $this->codes->revoke($warehouse);
        InstanceAuditLog::record('warehouse.setup_code_revoked', $warehouse, ['revoked' => $count], $by);

        return $count;
    }

    /** When an archived warehouse may be removed from the screen, or null if it isn't archived. */
    public function removableOn(Warehouse $warehouse): ?Carbon
    {
        return $warehouse->status === 'archived' && $warehouse->archived_at ? $warehouse->archived_at->copy()->addDays(self::REMOVAL_WAIT_DAYS) : null;
    }

    /** The screen's 30-day wait: an archived warehouse can't be removed from it sooner (the command can). */
    public function assertRemovalWaitOver(Warehouse $warehouse): void
    {
        $on = $this->removableOn($warehouse);
        if ($on && $on->isFuture()) {
            throw new RuntimeException("{$warehouse->display_name} was archived less than ".self::REMOVAL_WAIT_DAYS.' days ago; it can be removed here from '.$on->toFormattedDateString().". (The command `php artisan warehouse:remove {$warehouse->id}` removes an archived warehouse at once.)");
        }
    }
}
