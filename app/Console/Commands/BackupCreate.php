<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCreate extends Command
{
    protected $signature = 'backup:create {--uploads : also include uploaded files}';

    protected $description = 'Create an on-demand backup zip right now (waits until it finishes)';

    public function handle(BackupService $service): int
    {
        set_time_limit(0);
        $backup = Backup::create(['status' => 'queued', 'include_uploads' => (bool) $this->option('uploads')]);
        $service->run($backup);
        $backup->refresh();

        if ($backup->status !== 'complete') {
            $this->error($backup->error ?: 'Backup failed.');

            return self::FAILURE;
        }
        $this->info($backup->path());

        return self::SUCCESS;
    }
}
