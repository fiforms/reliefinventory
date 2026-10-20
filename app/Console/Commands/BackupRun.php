<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;

/** `backup:run <id>` is what the web request starts (detached); `backup:create` is the same thing from a shell. */
class BackupRun extends Command
{
    protected $signature = 'backup:run {id : backups.id of a queued backup}';

    protected $description = 'Build the zip for a queued on-demand backup (started by the admin page)';

    public function handle(BackupService $service): int
    {
        set_time_limit(0);
        $backup = Backup::findOrFail($this->argument('id'));
        $service->run($backup);

        return $backup->fresh()->status === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
