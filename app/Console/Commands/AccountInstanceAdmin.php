<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Accounts\Account;
use Illuminate\Console\Command;

/**
 * Make an account an instance admin, or stop it being one (MULTI_WAREHOUSE.md §6). The flag can only be set
 * here or from the instance console — never from inside a warehouse, and never from a backup.
 */
class AccountInstanceAdmin extends Command
{
    protected $signature = 'account:instance-admin {email : The login email} {--revoke : Remove instance-admin powers instead}';

    protected $description = 'Grant (or with --revoke, remove) instance-admin powers for a login account.';

    public function handle(): int
    {
        $account = Account::findByEmail($this->argument('email'));
        if (! $account) {
            $this->error('No login account has that email.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        if (! $grant && $account->is_instance_admin && Account::where('is_instance_admin', true)->count() === 1) {
            $this->error('This is the only instance admin; make someone else one first.');

            return self::FAILURE;
        }

        $account->forceFill(['is_instance_admin' => $grant])->save();
        $this->info($grant ? "{$account->email} is now an instance admin." : "{$account->email} is no longer an instance admin.");

        return self::SUCCESS;
    }
}
