<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The old bare-metal schedule, `storage/app/backup-settings.conf` (written by the System Administration panel, read by scripts/update.sh), as
 * a `backup_schedule` row, so an install that moves to the one scheduler (BackupSchedule) keeps the schedule it had. Used once, by the
 * migration that creates the table; nothing else reads that file any more. It is parsed key by key and never sourced.
 *
 * How the old settings map:
 *  - frequency daily  -> the daily tier, kept KEEP_DAILY. frequency weekly -> the weekly tier on BACKUP_DOW, kept KEEP_DAILY (the old "daily"
 *    directory held exactly those weekly backups), and no daily tier.
 *  - HOURLY_BACKUPS=1 -> the hourly tier, kept KEEP_HOURLY (at most 168).
 *  - the monthly tier (always made before) -> monthly, kept KEEP_MONTHLY (0 turns it off; at most 36). KEEP_YEARLY has no equivalent: there is
 *    no yearly tier, so those copies are not carried forward (36 monthlies cover three years).
 *  - BACKUP_HOUR was in BACKUP_TZ; the new schedule is in the server's own timezone, so the hour is converted once (it will not follow daylight saving).
 *  - uploaded files were never part of the tiers, and stay out.
 */
final class LegacyBackupSettings
{
    private const DEFAULTS = [
        'BACKUP_FREQUENCY' => 'daily', 'BACKUP_HOUR' => '2', 'BACKUP_DOW' => '7', 'HOURLY_BACKUPS' => '0', 'BACKUP_TZ' => 'America/Los_Angeles',
        'KEEP_HOURLY' => '48', 'KEEP_DAILY' => '14', 'KEEP_MONTHLY' => '12', 'KEEP_YEARLY' => '3',
    ];

    /** @return array<string, string>|null the settings (defaults filled in), or null when there is no readable file */
    public static function read(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $values = self::DEFAULTS;
        foreach (file($path) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m) && isset(self::DEFAULTS[$m[1]])) {
                $values[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        return $values;
    }

    /** The whole step: the `backup_schedule` columns the file at $path means, or null when there is no readable file. */
    public static function scheduleRow(string $path, string $serverTimezone): ?array
    {
        $legacy = self::read($path);

        return $legacy === null ? null : self::toSchedule($legacy, $serverTimezone);
    }

    /**
     * The `backup_schedule` columns these settings mean.
     *
     * @param  array<string, string>  $legacy  from read()
     * @param  string  $serverTimezone  the timezone the new schedule's hours are in (config app.timezone)
     */
    public static function toSchedule(array $legacy, string $serverTimezone): array
    {
        $int = fn (string $key, int $min, int $max) => max($min, min($max, (int) $legacy[$key]));
        $weekly = $legacy['BACKUP_FREQUENCY'] === 'weekly';
        $monthlyKeep = $int('KEEP_MONTHLY', 0, 36);

        return [
            'hourly_enabled' => $legacy['HOURLY_BACKUPS'] === '1',
            'hourly_keep' => $int('KEEP_HOURLY', 1, 168),
            'daily_enabled' => ! $weekly,
            'daily_hour' => self::hourIn($serverTimezone, $int('BACKUP_HOUR', 0, 23), $legacy['BACKUP_TZ']),
            'daily_keep' => $int('KEEP_DAILY', 1, 90),
            'daily_with_files' => false,
            'weekly_enabled' => $weekly,
            'weekly_dow' => $int('BACKUP_DOW', 1, 7) % 7,   // 1=Mon..7=Sun -> 0=Sun..6=Sat
            'weekly_keep' => $int('KEEP_DAILY', 1, 52),
            'weekly_with_files' => false,
            'monthly_enabled' => $monthlyKeep > 0,
            'monthly_keep' => max(1, $monthlyKeep),
            'monthly_with_files' => false,
        ];
    }

    /** $hour o'clock in $from, as an hour of day in $to (an unknown timezone name leaves the hour as it was). */
    public static function hourIn(string $to, int $hour, string $from): int
    {
        try {
            return Carbon::now($from)->setTime($hour, 0)->setTimezone($to)->hour;
        } catch (Throwable) {
            return $hour;
        }
    }
}
