<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\BackupService;
use App\Services\RestoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Erase the database and return the instance to a brand-new, never-set-up state (schema + essential lookup data, no
 * user accounts, no setup record) — e.g. after a forced `backup:restore` failed and left the database empty or half-loaded.
 *
 * Deliberately awkward to run by accident or by script. There is NO --force / --yes / --no-interaction path: it only
 * proceeds from a real terminal, and only after the operator types the confirmation phrase themselves. A command
 * pasted from the Internet, piped input, a cron job or a CI step all stop at the first check.
 */
class SetupReset extends Command
{
    public const PHRASE = 'YES-DELETE-ALL-MY-DATA';

    protected $signature = 'setup:reset';

    protected $description = 'DESTROYS the whole database and returns this instance to a brand-new, never-set-up state (interactive terminal only)';

    /** Overridable so a test can stand in for a person at a keyboard; nothing in production does. */
    protected function hasTerminal(): bool
    {
        return $this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN);
    }

    public function handle(RestoreService $restore): int
    {
        if (! $this->hasTerminal()) {
            $this->error('This command only runs from an interactive terminal, typed by a person. It cannot be scripted, piped, or run with --no-interaction.');

            return self::FAILURE;
        }

        $connection = config('database.connections.'.config('database.default'));
        $this->newLine();
        $this->line('<fg=red;options=bold>THIS ERASES THE ENTIRE DATABASE.</>');
        $this->line("Database: {$connection['database']} on {$connection['host']}");
        $this->describeContents();
        $this->line('  • Every table is dropped and rebuilt empty, then the essential lookup data is loaded.');
        $this->line('  • The instance becomes brand new: the first-run setup / restore wizard will be open to whoever reaches it.');
        $this->line('  • Uploaded files and backup zips on disk are NOT touched.');
        $this->line('  • This cannot be undone. Make sure you have a backup zip you can restore from:');
        $this->listBackups();
        $this->newLine();

        $typed = $this->ask('Type '.self::PHRASE.' exactly to continue');
        if ($typed !== self::PHRASE) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $restore->resetInstance();
        } catch (Throwable $e) {
            $this->error('The reset failed part-way: '.$e->getMessage());
            $this->line('The database may be empty or incomplete. Run this command again, or restore a backup with: php artisan backup:restore <zip>');

            return self::FAILURE;
        }
        @unlink($restore->dir().'/status.json'); // the failed restore's message
        // A failed forced restore leaves the warehouse out of service on purpose; a rebuilt, brand-new one is fine to serve again.
        $warehouse = Warehouse::current();
        if ($warehouse->status === 'restoring') {
            $warehouse->forceFill(['status' => 'active'])->save();
        }

        $this->info('The database was erased and rebuilt. This instance is now brand new.');
        $this->line('Next, either:');
        $this->line('  • restore a backup:  php artisan backup:restore <database zip> [--storage=<files zip>]');
        $this->line('  • or set it up from scratch in the browser (the /install wizard).');
        $this->line('If this server is reachable from the internet, set RI_SETUP_CODE first so a stranger cannot claim the new instance.');

        return self::SUCCESS;
    }

    /** What is about to be lost, as far as the (possibly damaged) database can say. */
    private function describeContents(): void
    {
        try {
            $this->line('Currently holds: '.DB::table('people')->count().' people, '.DB::table('orderdonations')->count().' donations/orders.');
        } catch (Throwable) {
            $this->line('Currently holds: (the database could not be read — it may already be empty or damaged)');
        }
    }

    private function listBackups(): void
    {
        $zips = glob(app(BackupService::class)->dir().'/relief-backup-*.zip') ?: [];
        usort($zips, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        if ($zips === []) {
            $this->line('    (none found in '.app(BackupService::class)->dir().')');

            return;
        }
        foreach (array_slice($zips, 0, 3) as $zip) {
            $this->line('    '.$zip.'  ('.date('Y-m-d H:i', filemtime($zip)).')');
        }
    }
}
