<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Support\SnapshotRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton (always id = 1): when this warehouse's rollback snapshots are made and how many of each tier are kept. The Docker scheduler
 * asks every warehouse every hour (`backup:scheduled`); due() decides what, if anything, this hour's single zip is for. One zip can serve
 * several tiers at once (the Sunday 02:00 zip is the hourly, the daily and the weekly one), which is also what keeps disk use down.
 * Hourly and daily are database-only unless a tier is told to include the files; weekly and monthly are where files usually belong.
 */
class BackupSchedule extends Model
{
    public const TIERS = ['hourly', 'daily', 'weekly', 'monthly'];

    protected $table = 'backup_schedule';

    // One row, id 1, not auto-incremented.
    public $incrementing = false;

    // Written from the validated settings form only (BackupController::updateSchedule).
    protected $guarded = ['id'];

    protected $casts = [
        'hourly_enabled' => 'boolean', 'daily_enabled' => 'boolean', 'weekly_enabled' => 'boolean', 'monthly_enabled' => 'boolean',
        'daily_with_files' => 'boolean', 'weekly_with_files' => 'boolean', 'monthly_with_files' => 'boolean',
        'last_run_at' => 'datetime',
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

    public function enabled(string $tier): bool
    {
        return (bool) $this->{"{$tier}_enabled"};
    }

    public function keep(string $tier): int
    {
        return max(1, (int) $this->{"{$tier}_keep"});
    }

    public function withFiles(string $tier): bool
    {
        return $tier !== 'hourly' && (bool) $this->{"{$tier}_with_files"};
    }

    /** What a zip made at $now would have to carry. Empty = nothing is due this hour. */
    public function due(?Carbon $now = null): array
    {
        $now ??= now();
        $due = [];

        // Hourly: any hour in which none was made (a little under an hour, so a scheduler that drifts by a few minutes isn't skipped).
        if ($this->enabled('hourly') && $this->newest('hourly')?->lt($now->copy()->subMinutes(50)) !== false) {
            $due[] = 'hourly';
        }

        // The others wait for the quiet hour (not before it), and are made at the first check after it that finds them missing —
        // so a warehouse that was suspended at the hour still gets its backup when it comes back.
        $hour = (int) $this->daily_hour;
        if ($this->enabled('daily') && SnapshotRules::dailyDue($this->newest('daily'), $now, $hour)) {
            $due[] = 'daily';
        }
        if ($this->enabled('weekly') && SnapshotRules::weeklyDue($this->newest('weekly'), $now, $hour, (int) $this->weekly_dow)) {
            $due[] = 'weekly';
        }
        if ($this->enabled('monthly') && SnapshotRules::monthlyDue($this->newest('monthly'), $now, $hour)) {
            $due[] = 'monthly';
        }

        return $due;
    }

    /** Whether a zip for these tiers has to include the uploaded files. */
    public function scopeFor(array $tiers): string
    {
        foreach ($tiers as $tier) {
            if ($this->withFiles($tier)) {
                return 'full';
            }
        }

        return 'database';
    }

    private function newest(string $tier): ?Carbon
    {
        $finished = Backup::where('status', 'complete')->where('tiers', 'like', "%{$tier}%")->max('finished_at');

        return $finished ? Carbon::parse($finished) : null;
    }
}
