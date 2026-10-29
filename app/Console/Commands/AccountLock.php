<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;

/** Lock a login (it cannot sign in anywhere and its sessions end) or unlock it. Nothing is deleted. */
class AccountLock extends Command
{
    use InstanceCommand;

    protected $signature = 'account:lock {email : The login email} {--unlock : Unlock it instead}';

    protected $description = 'Lock a login account (no sign-in in any warehouse, sessions ended), or --unlock it.';

    public function handle(): int
    {
        if (! $account = $this->accountOrNull($this->argument('email'))) {
            return self::FAILURE;
        }

        if ($this->option('unlock')) {
            $account->forceFill(['locked_at' => null])->save();
            InstanceAuditLog::record('account.unlocked', null, ['email' => $account->email]);
            $this->components->info("{$account->email} is unlocked.");

            return self::SUCCESS;
        }

        $others = Account::where('is_instance_admin', true)->whereNull('locked_at')->whereKeyNot($account->id)->exists();
        if ($account->is_instance_admin && ! $others) {
            $this->components->error('This is the only unlocked instance admin; make another one first (account:instance-admin).');

            return self::FAILURE;
        }
        $account->forceFill(['locked_at' => now()])->save();
        InstanceAuditLog::record('account.locked', null, ['email' => $account->email]);
        $this->components->info("{$account->email} is locked and signed out everywhere.");

        return self::SUCCESS;
    }
}
