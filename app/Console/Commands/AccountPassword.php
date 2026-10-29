<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/** Set a login's password (a forgotten one, or a first one). Ends the login's other sessions in every warehouse (Account's auth_epoch). */
class AccountPassword extends Command
{
    use InstanceCommand;

    protected $signature = 'account:password {email : The login email} {--password-file= : A file holding the new password (otherwise asked for)}';

    protected $description = 'Set a login account\'s password; its signed-in sessions end.';

    public function handle(): int
    {
        if (! $account = $this->accountOrNull($this->argument('email'))) {
            return self::FAILURE;
        }
        if (($password = $this->newPassword()) === null) {
            return self::FAILURE;
        }

        $account->forceFill(['password' => Hash::make($password), 'email_verified_at' => $account->email_verified_at ?? now()])->save();
        InstanceAuditLog::record('account.password_set', null, ['email' => $account->email]);
        $this->components->info("The password for {$account->email} was changed and its sessions ended.");

        return self::SUCCESS;
    }
}
