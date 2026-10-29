<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Accounts\Account;
use App\Models\Control\ProvisioningCommand;
use App\Models\Control\Warehouse;
use App\Services\BackupService;
use App\Services\InstanceBackupService;
use App\Services\InstanceRecoveryKeys;
use Illuminate\Console\Command;
use Throwable;

/** One screen for "is this instance healthy": maintenance mode, every warehouse's state, who runs it, the provisioner's queue, the last instance backup and free disk. */
class InstanceStatus extends Command
{
    protected $signature = 'instance:status';

    protected $description = 'Show the instance at a glance: maintenance mode, warehouses, instance admins, provisioner queue, last instance backup, free disk.';

    public function handle(InstanceBackupService $backups): int
    {
        $this->line('Maintenance mode: '.(app()->isDownForMaintenance() ? '<fg=red>ON (the whole instance answers 503; `instance:maintenance off` ends it)</>' : 'off'));

        $warehouses = Warehouse::orderByDesc('is_default')->orderBy('created_at')->get();
        $this->newLine();
        $this->table(['Id', 'Name', 'Status', 'Migrations', 'Default', 'Training'], $warehouses->map(fn (Warehouse $w) => [
            $w->id, $w->display_name, $w->status, $w->migration_state, $w->is_default ? 'yes' : '', $w->is_training ? 'yes' : '',
        ]));
        $unwell = $warehouses->reject(fn (Warehouse $w) => in_array($w->status, ['active', 'archived', 'awaiting_setup', 'suspended'], true));
        foreach ($unwell as $w) {
            $this->components->warn("{$w->display_name} is {$w->status}: ".match ($w->status) {
                'restoring' => '`warehouse:resume '.$w->id.'` ends a restore that left it out of service.',
                'maintenance' => '`warehouse:activate '.$w->id.'` ends it.',
                default => 'it is not serving anyone; see MULTI_WAREHOUSE.md §10.',
            });
        }

        $admins = Account::where('is_instance_admin', true)->get();
        $usable = $admins->whereNull('locked_at')->count();
        $this->line("Instance admins: {$admins->count()} ({$usable} not locked)".($usable === 0 ? ' <fg=red>— nobody can run the instance from the browser; `account:instance-admin` or `account:create --instance-admin`</>' : ''));

        try {
            $pending = ProvisioningCommand::where('status', 'pending')->orderBy('id')->get();
            $line = 'Provisioner queue: '.$pending->count().' waiting';
            if ($pending->isNotEmpty() && $pending->first()->created_at->lt(now()->subMinute())) {
                $line .= ' <fg=red>(the oldest for '.$pending->first()->created_at->diffForHumans(null, true).': is the provisioner container running?)</>';
            }
            $this->line($line);
        } catch (Throwable) {
            $this->line('Provisioner queue: (the control schema has not been migrated)');
        }

        try {
            $recovery = app(InstanceRecoveryKeys::class)->payload();
            $this->line('Recovery key: '.match ($recovery['state']) {
                'none' => '<fg=red>none — automatic backups cannot restore the warehouses\' encrypted settings; `instance:recovery-key generate`</>',
                'unconfirmed' => "<fg=yellow>{$recovery['fingerprint']}, but nobody confirmed storing the private half (`instance:recovery-key acknowledge`, or make a new one)</>",
                default => "{$recovery['fingerprint']} (backups are sealed to it)",
            });
        } catch (Throwable) {
            $this->line('Recovery key: (the control schema has not been migrated)');
        }

        $last = collect(glob($backups->dir().'/relief-instance-*.zip') ?: [])->sortByDesc(fn ($f) => filemtime($f))->first();
        $this->line('Last instance backup: '.($last ? basename($last).' ('.date('Y-m-d H:i', filemtime($last)).', '.round(filesize($last) / 1048576, 1).' MB)' : 'none (`instance:backup`, in the provisioner container)'));

        foreach (['backups' => app(BackupService::class)->rootDir(), 'files' => config('warehouses.storage_dir')] as $label => $path) {
            $free = is_dir($path) ? @disk_free_space($path) : false;
            if ($free !== false) {
                $this->line(ucfirst($label).' disk free: '.round($free / 1073741824, 1).' GB');
            }
        }

        return self::SUCCESS;
    }
}
