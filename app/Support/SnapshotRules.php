<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * When a scheduled backup is due, shared by a warehouse's snapshot schedule (App\Models\BackupSchedule) and the whole-instance one
 * (App\Models\Control\InstanceBackupSchedule) so the two cannot drift apart. $last is when the newest backup of that kind finished
 * (null: never). They are asked every hour, so "due" means "missing since the quiet hour", which also catches up a backup that was
 * missed while the machine or the warehouse was off.
 */
final class SnapshotRules
{
    /** Once per calendar day, from $hour on. */
    public static function dailyDue(?Carbon $last, Carbon $now, int $hour): bool
    {
        return $now->hour >= $hour && ($last === null || $last->lt($now->copy()->startOfDay()));
    }

    /** On the weekly day (from $hour on), or at the next check once the last one is over a week old. */
    public static function weeklyDue(?Carbon $last, Carbon $now, int $hour, int $dayOfWeek): bool
    {
        if ($now->hour < $hour) {
            return false;
        }
        if ($last === null) {
            return $now->dayOfWeek === $dayOfWeek;
        }

        return $last->lt($now->copy()->startOfDay())
            && ($now->dayOfWeek === $dayOfWeek || $last->lt($now->copy()->subDays(7)->startOfDay()));
    }

    /** Once per calendar month, from $hour on. */
    public static function monthlyDue(?Carbon $last, Carbon $now, int $hour): bool
    {
        return $now->hour >= $hour && ($last === null || $last->lt($now->copy()->startOfMonth()));
    }
}
