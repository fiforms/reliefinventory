<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\BackupCompatibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MigrateCheckDowngrade extends Command
{
    protected $signature = 'migrate:check-downgrade';

    protected $description = 'Fail if the database has applied migrations this code does not ship (i.e. an older version was started on a newer database). Used by the Docker entrypoint.';

    public function handle(BackupCompatibility $compat): int
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return self::SUCCESS; // brand-new database
            }
            $applied = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable $e) {
            // Can't tell (database trouble); the migrate step reports connection problems itself.
            return self::SUCCESS;
        }

        $unknown = array_values(array_diff($applied, $compat->knownMigrations()));
        if ($unknown === []) {
            return self::SUCCESS;
        }

        $this->error('This database was migrated by a NEWER version of Relief Inventory than the one now starting ('
            .count($unknown).' migration(s) this version does not have, newest: '.end($unknown).').');
        $this->line('An older version cannot run against it, and it cannot undo the newer version\'s changes.');
        $this->line('Either upgrade back to the newer version (set the image tag in compose.yaml back to :latest, or the');
        $this->line('version you were on), or restore the backup taken just before that upgrade.');

        return self::FAILURE;
    }
}
