<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

/**
 * Docker start-up, once (MULTI_WAREHOUSE.md §15): when the default warehouse's key is still the instance's
 * APP_KEY — an existing instance just moved to per-warehouse keys — give the instance a new APP_KEY. The old one
 * has been visible to warehouse administrators, and APP_KEY now signs account-level links. Everyone is logged
 * out once. Bare metal keeps one key (it is single-warehouse) and never runs this.
 */
class InstanceRotateAppKey extends Command
{
    protected $signature = 'instance:rotate-app-key {--file= : The file APP_KEY is kept in (rewritten in place)}';

    protected $description = 'Docker: give the instance a new APP_KEY once the default warehouse has its own key.';

    public function handle(): int
    {
        $default = Warehouse::default();
        if (! $default?->hasKey()) {
            $this->error('The default warehouse has no key file yet; run instance:migrate first.');

            return self::FAILURE;
        }
        if ($default->key() !== config('app.key')) {
            return self::SUCCESS;   // already separate
        }

        $file = (string) $this->option('file');
        if ($file === '') {
            $this->warn('APP_KEY is still the default warehouse\'s key. It is set outside the app, so set a new one yourself'
                .' (php artisan key:generate --show) and restart; the warehouse keeps the old one in its key file.');

            return self::SUCCESS;
        }

        $tmp = $file.'.tmp';
        $old = umask(077);
        try {
            file_put_contents($tmp, 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')))) !== false && rename($tmp, $file)
                || throw new \RuntimeException("Could not write {$file}.");
        } finally {
            umask($old);
        }
        $this->info('Gave the instance a new APP_KEY; the default warehouse keeps the old one as its own key. Everyone is logged out once.');

        return self::SUCCESS;
    }
}
