<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * This warehouse's daily backup, for the Docker scheduler (`warehouse:each "backup:scheduled"`, hourly after the backup hour): makes the
 * usual recorded backup unless today's has already been made, remembered by a marker file in the warehouse's own backup folder. The
 * scheduler can therefore ask every warehouse every hour without a second dump, and a warehouse that was suspended at the hour still
 * gets one when it comes back. The backup itself waits its turn behind other warehouses' (BackupService::instanceLock()).
 */
class BackupScheduled extends Command
{
    protected $signature = 'backup:scheduled {--scope=database : database or full}';

    protected $description = 'Make this warehouse\'s daily backup unless today\'s exists.';

    public function handle(BackupService $service): int
    {
        $dir = $service->dir();
        $marker = $dir.'/.daily-'.now()->format('Ymd');
        if (is_file($marker)) {
            $this->line('Today\'s backup was already made.');

            return self::SUCCESS;
        }

        $code = Artisan::call('backup:create', ['--scope' => $this->option('scope')], $this->output);
        if ($code !== self::SUCCESS) {
            return self::FAILURE;
        }

        @mkdir($dir, 0750, true);
        touch($marker);
        foreach (glob($dir.'/.daily-*') ?: [] as $old) {
            if ($old !== $marker && filemtime($old) < now()->subDays(2)->getTimestamp()) {
                @unlink($old);
            }
        }

        return self::SUCCESS;
    }
}
