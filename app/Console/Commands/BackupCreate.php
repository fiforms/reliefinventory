<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * THE way to make a backup zip from a shell or a script. scripts/update.sh (bare-metal tiered backups) and the
 * Docker scheduler / pre-migration backup call this instead of carrying their own dump commands, so every
 * platform produces the same zip with the same manifest.
 */
class BackupCreate extends Command
{
    protected $signature = 'backup:create
        {--scope=database : What to include: database, full (database + uploaded files) or storage (uploaded files only)}
        {--dest= : Write the zip into this directory with no database record and no expiry (the caller manages retention). Works with the database down — use it for disaster recovery}
        {--name= : With --dest: the file name without .zip (default relief-backup-<time>-<scope>)}';

    protected $description = 'Create a backup zip (database, database + files, or files only) and print its path';

    public function handle(BackupService $service): int
    {
        set_time_limit(0);

        $scope = (string) $this->option('scope');
        if (! in_array($scope, BackupService::SCOPES, true)) {
            $this->error("Unknown --scope '{$scope}'. Use one of: ".implode(', ', BackupService::SCOPES).'.');

            return self::FAILURE;
        }
        $dest = $this->option('dest') ?: null;
        $name = $this->option('name') ?: null;
        if ($name !== null && ($dest === null || ! preg_match('/^[A-Za-z0-9._-]+$/', $name) || str_ends_with($name, '.zip'))) {
            $this->error('--name needs --dest, must be letters, digits, dots, dashes or underscores, and has no .zip on the end.');

            return self::FAILURE;
        }

        if ($dest !== null) {
            return $this->createUnrecorded($service, $scope, $dest, $name);
        }

        // The ordinary case: a row so the admin page lists it and it expires. If the row can't be written (the
        // database is down, or a pre-migration backup runs before the table has this version's columns), still
        // make the file — a backup that can't be recorded is far better than none.
        try {
            $backup = Backup::create(['status' => 'queued', 'scope' => $scope]);
        } catch (Throwable $e) {
            $this->warn('Could not record the backup in the database ('.substr($e->getMessage(), 0, 120).'); writing the file anyway. It will not be listed in the app.');

            return $this->createUnrecorded($service, $scope, $service->dir(), null);
        }

        $service->run($backup);
        $backup->refresh();
        if ($backup->status !== 'complete') {
            $this->error($backup->error ?: 'Backup failed.');

            return self::FAILURE;
        }
        $this->line($backup->path());

        return self::SUCCESS;
    }

    private function createUnrecorded(BackupService $service, string $scope, string $dir, ?string $name): int
    {
        try {
            $result = $service->createIn($dir, $scope, $name);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line($result['path']);

        return self::SUCCESS;
    }
}
