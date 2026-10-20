<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\RestoreService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restore from backup zips on disk. The same pipeline as the first-run wizard (RestoreService), plus --force for an
 * instance that has already been set up — the database-corruption recovery: the latest good database zip, and
 * optionally a separate (better, or newer) copy of the files.
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {database? : Backup zip that supplies the database (scope database or full). A full zip supplies the files too, unless --storage is given}
        {--storage= : A separate zip that supplies the files (scope storage or full). Any database inside it is ignored}
        {--force : Restore onto an instance that has already been set up. REPLACES its database; the site is in maintenance mode meanwhile}';

    protected $description = 'Restore a database and/or uploaded files from backup zip(s), e.g. after database corruption';

    public function handle(RestoreService $restore): int
    {
        set_time_limit(0);
        $database = $this->argument('database');
        $storage = $this->option('storage') ?: null;
        if ($database === null && $storage === null) {
            $this->error('Give a backup zip for the database, a --storage zip for the files, or both.');

            return self::FAILURE;
        }

        $what = [];
        if ($database !== null) {
            $what[] = "the database from {$database}";
        }
        if ($storage !== null) {
            $what[] = "the files from {$storage}";
        } elseif ($database !== null) {
            $what[] = 'the files from the same zip, if it has them';
        }
        $this->line('Restoring '.implode(' and ', $what).'.');
        if ($database !== null && $this->option('force') && $this->input->isInteractive() && ! $this->confirm('This REPLACES the current database. Continue?', false)) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        try {
            $result = $restore->runFromFiles($database, $storage, (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore complete.');
        foreach ($result['notes'] as $note) {
            $this->line("Note: {$note}");
        }
        if (! empty($result['blanked'])) {
            $this->warn('Re-enter these settings (encrypted with a key this instance does not have): '.implode(', ', $result['blanked']));
        }
        if (! empty($result['admin_missing'])) {
            $this->warn('The restored data has no administrator account; open the site to create one.');
        }

        return self::SUCCESS;
    }
}
