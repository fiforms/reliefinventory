<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;

/**
 * Flips people.volunteer_active automatically for anyone on a known-duration
 * volunteer_window, so an admin never has to come back and adjust it: true
 * on volunteer_window_start, false the day after volunteer_window_end (they
 * stay active through their actual last day). Idempotent — only ever
 * touches rows exactly on one of those two transition days, leaving manual
 * admin toggles in between alone.
 *
 * Meant to run hourly via a new systemd timer/service pair mirroring
 * reliefinventory-backup.timer (see scripts/systemd/) — this app has no
 * Laravel Schedule::-based cron, but does already run app-triggered work
 * off a proven hourly systemd timer for backups, so this reuses that
 * pattern rather than introducing a new one.
 */
class SyncVolunteerActiveWindows extends Command
{
    protected $signature = 'volunteers:sync-active-windows';

    protected $description = 'Activate/deactivate volunteers whose volunteer_window start/end date is today';

    public function handle(): int
    {
        $today = now()->toDateString();

        $activated = Person::where('is_volunteer', true)
            ->where('volunteer_active', false)
            ->whereDate('volunteer_window_start', $today)
            ->update(['volunteer_active' => true]);

        $deactivated = Person::where('is_volunteer', true)
            ->where('volunteer_active', true)
            ->whereDate('volunteer_window_end', '=', now()->subDay()->toDateString())
            ->update(['volunteer_active' => false]);

        $this->info("Activated {$activated}, deactivated {$deactivated}.");

        return self::SUCCESS;
    }
}
