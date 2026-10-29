<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Removing a warehouse (MULTI_WAREHOUSE.md §6): an instance-admin decision, in two steps.
 *
 *  - **archive**: the undoable step. The warehouse stops serving (ResolveWarehouse, the chooser and the queue already treat anything
 *    but active as not there) and keeps everything. `unarchive` puts it back as it was.
 *  - **remove**: the instance admin does it whenever they choose; no waiting period is forced (they could run the same commands on
 *    the host anyway). It needs the warehouse's name typed, takes a **final full backup** first (kept for good in
 *    `<backup dir>/removed-warehouses/<id>/`), then drops the schema and database user (the provisioner), deletes its files and key,
 *    removes the logins that belonged only to it, and finally its control rows. Every step can be repeated, so an interrupted removal
 *    is finished by running it again.
 *
 * The warehouse row is deleted last: the provisioner re-checks each command against it, and a half-removed warehouse stays `archived`.
 * Everything is written to the instance audit log.
 */
class WarehouseRemoval
{
    public function __construct(private WarehouseContext $context) {}

    public function archive(Warehouse $warehouse, ?string $by = null): void
    {
        $this->assertRemovable($warehouse);
        if ($warehouse->status === 'archived') {
            throw new RuntimeException("{$warehouse->display_name} is already archived.");
        }

        $warehouse->forceFill(['status_before_archive' => $warehouse->status, 'status' => 'archived', 'archived_at' => now()])->save();
        InstanceAuditLog::record('warehouse.archived', $warehouse, ['was' => $warehouse->status_before_archive], $by);
    }

    public function unarchive(Warehouse $warehouse, ?string $by = null): void
    {
        if ($warehouse->status !== 'archived') {
            throw new RuntimeException("{$warehouse->display_name} is not archived.");
        }

        $warehouse->forceFill(['status' => $warehouse->status_before_archive ?: 'active', 'status_before_archive' => null, 'archived_at' => null])->save();
        InstanceAuditLog::record('warehouse.unarchived', $warehouse, ['now' => $warehouse->status], $by);
    }

    /**
     * Remove an archived warehouse for good.
     *
     * @param  bool  $backup  take the final backup first; false only for a warehouse that has no database left (a removal interrupted
     *                        after the drop, or a provisioning that never finished)
     * @return array{backup: ?string, accounts_removed: list<string>, accounts_kept: int, notes: list<string>}
     *
     * @throws RuntimeException with the reason, before anything is deleted, unless it says otherwise
     */
    public function remove(Warehouse $warehouse, string $typedName, ?string $by = null, bool $backup = true): array
    {
        $this->assertRemovable($warehouse);
        if ($warehouse->status !== 'archived') {
            throw new RuntimeException("Archive {$warehouse->display_name} first; only an archived warehouse can be removed.");
        }
        if (trim($typedName) !== $warehouse->display_name) {
            throw new RuntimeException('The name typed does not match this warehouse. Nothing was removed.');
        }

        $notes = [];
        $path = $backup ? $this->finalBackup($warehouse) : null;

        $this->dropDatabase($warehouse, $by);
        $this->deleteFiles($warehouse, $notes);
        $accounts = $this->forgetAccounts($warehouse);
        $this->deleteControlRows($warehouse);

        InstanceAuditLog::record('warehouse.removed', $warehouse, [
            'id' => $warehouse->id, 'schema' => $warehouse->schema_name, 'was_training' => (bool) $warehouse->is_training, 'backup' => $path,
            'accounts_removed' => $accounts['removed'], 'accounts_kept' => $accounts['kept'], 'notes' => $notes,
        ], $by);

        return ['backup' => $path, 'accounts_removed' => $accounts['removed'], 'accounts_kept' => $accounts['kept'], 'notes' => $notes];
    }

    /**
     * The logins that belonged only to this warehouse go with it (§6, "Removing a warehouse"). The sole-membership test: an account stays if
     * it is an instance admin or has a membership in another *real* warehouse (training memberships don't count, so a login that
     * belonged to this warehouse and a training one goes). Any other login just loses this membership. Removed logins' people in
     * training warehouses lose their `account_id` copy; invitations to or from this warehouse, and offers to a removed login, are deleted.
     *
     * @return array{removed: list<string>, kept: int}
     */
    public function forgetAccounts(Warehouse $warehouse): array
    {
        $removed = [];
        $kept = 0;
        $trainingPeople = [];   // warehouse id => account ids whose person row there loses its copy

        foreach (Membership::where('warehouse_id', $warehouse->id)->get() as $membership) {
            $account = Account::find($membership->account_id);
            if (! $account) {
                $membership->delete();

                continue;
            }
            $realElsewhere = Membership::where('account_id', $account->id)->where('warehouse_id', '!=', $warehouse->id)->where('is_training', false)->exists();
            if ($account->is_instance_admin || $realElsewhere) {
                $membership->delete();
                $kept++;

                continue;
            }

            foreach (Membership::where('account_id', $account->id)->where('warehouse_id', '!=', $warehouse->id)->pluck('warehouse_id') as $other) {
                $trainingPeople[$other][] = $account->id;
            }
            $removed[] = $account->email;
            $account->delete();   // its memberships go with it
        }

        foreach ($trainingPeople as $id => $accountIds) {
            if ($other = Warehouse::find($id)) {
                try {
                    $this->context->run($other, fn () => DB::table('people')->whereIn('account_id', $accountIds)->update(['account_id' => null]));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        MembershipInvitation::where('warehouse_id', $warehouse->id)->orWhereIn('email', $removed)->delete();

        return ['removed' => $removed, 'kept' => $kept];
    }

    private function assertRemovable(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            throw new RuntimeException('The default warehouse cannot be archived or removed: unprefixed machine URLs and everything paired to it live there.');
        }
    }

    /** A full backup of the warehouse as it is, kept in <backup dir>/removed-warehouses/<id>/ until someone deletes it. */
    private function finalBackup(Warehouse $warehouse): string
    {
        $dir = rtrim(config('backup.dir'), '/').'/removed-warehouses/'.$warehouse->id;
        $name = Str::slug($warehouse->display_name).'-'.$warehouse->id.'-'.now()->format('Ymd-His');

        try {
            $result = $this->context->run($warehouse, fn () => app(BackupService::class)->createIn($dir, 'full', $name));
        } catch (Throwable $e) {
            throw new RuntimeException('The final backup could not be taken, so nothing was removed: '.$e->getMessage()
                .' Only if the warehouse has no database left (an interrupted removal, or a provisioning that never finished) may the backup be skipped.');
        }

        return $result['path'];
    }

    private function dropDatabase(Warehouse $warehouse, ?string $by): void
    {
        // Nothing may still be using it: close this process's connection first (the web process held one for the backup).
        $this->context->forget();
        if (ProvisioningClient::applies()) {
            app(ProvisioningClient::class)->request($warehouse, 'drop', $by);
        } else {
            Provisioner::fromConfig()->drop($warehouse);
        }
    }

    /** Its files folder and its secrets (the key; the provisioner already removed the database password). */
    private function deleteFiles(Warehouse $warehouse, array &$notes): void
    {
        foreach ([dirname($warehouse->storagePath()), $warehouse->secretsPath()] as $directory) {
            if (is_dir($directory) && ! File::deleteDirectory($directory)) {
                $notes[] = "Could not delete {$directory}; remove it by hand.";
            }
        }
    }

    /** Queued jobs and failed jobs naming it, then the row itself (its domains, setup codes and provisioning commands go with it). */
    private function deleteControlRows(Warehouse $warehouse): void
    {
        WarehouseJobs::purge($warehouse);
        $warehouse->delete();
    }
}
