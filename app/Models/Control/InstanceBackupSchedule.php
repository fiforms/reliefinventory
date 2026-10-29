<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use App\Support\SnapshotRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton (always id = 1) in the control schema: when the whole-instance backup is made on its own, and how many are kept. Off until an
 * instance administrator turns it on. The scheduler container asks every hour (`instance:backup --scheduled`, which only asks the
 * provisioner when due() says so); the provisioner re-checks, makes the zip (InstanceBackupService::scheduled()) and records the outcome here.
 * A daily zip is database only unless asked otherwise; a weekly one may include every warehouse's files. Warehouse keys are never in a
 * scheduled zip (that needs a passphrase, and nobody is there to type it): they stay a deliberate `instance:backup --with-keys`.
 */
class InstanceBackupSchedule extends Model
{
    protected $connection = 'control';

    protected $table = 'instance_backup_schedule';

    // One row, id 1, not auto-incremented.
    public $incrementing = false;

    // Written from the validated settings form only (InstanceBackupController::updateSchedule).
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean', 'daily_with_files' => 'boolean', 'weekly_enabled' => 'boolean', 'weekly_with_files' => 'boolean',
        'last_daily_at' => 'datetime', 'last_weekly_at' => 'datetime', 'last_run_at' => 'datetime',
    ];

    public static function current(): self
    {
        if ($row = self::find(1)) {
            return $row;
        }
        // `id` is guarded, so firstOrCreate(['id' => 1]) could not set it.
        $row = (new self)->forceFill(['id' => 1]);
        $row->save();

        return $row->refresh();
    }

    /** Clamped, because the provisioner deletes zips by these numbers and the row is written by a less trusted process. */
    public function keepDaily(): int
    {
        return max(1, min(90, (int) $this->daily_keep));
    }

    public function keepWeekly(): int
    {
        return max(1, min(52, (int) $this->weekly_keep));
    }

    /** What a backup made at $now is for: `weekly` and/or `daily`. Empty = nothing is due. */
    public function due(?Carbon $now = null): array
    {
        $now ??= now();
        if (! $this->enabled) {
            return [];
        }
        $hour = max(0, min(23, (int) $this->daily_hour));
        $due = [];
        if (SnapshotRules::dailyDue($this->last_daily_at, $now, $hour)) {
            $due[] = 'daily';
        }
        if ($this->weekly_enabled && SnapshotRules::weeklyDue($this->last_weekly_at, $now, $hour, (int) $this->weekly_dow)) {
            $due[] = 'weekly';
        }

        return $due;
    }

    public function withFiles(array $tiers): bool
    {
        return (in_array('daily', $tiers, true) && $this->daily_with_files) || (in_array('weekly', $tiers, true) && $this->weekly_with_files);
    }
}
