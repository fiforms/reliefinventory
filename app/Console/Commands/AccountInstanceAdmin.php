<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;

/**
 * Make an account an instance admin, or stop it being one (MULTI_WAREHOUSE.md §6). The flag can only be set
 * here or from the instance console — never from inside a warehouse, and never from a backup.
 */
class AccountInstanceAdmin extends Command
{
    use InstanceCommand;

    protected $signature = 'account:instance-admin {email : The login email} {--revoke : Remove instance-admin powers instead}';

    protected $description = 'Grant (or with --revoke, remove) instance-admin powers for a login account.';

    public function handle(): int
    {
        if (! $account = $this->accountOrNull($this->argument('email'))) {
            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        if (! $grant && $account->is_instance_admin && Account::where('is_instance_admin', true)->count() === 1) {
            $this->error('This is the only instance admin; make someone else one first.');

            return self::FAILURE;
        }

        $account->forceFill(['is_instance_admin' => $grant])->save();
        InstanceAuditLog::record($grant ? 'account.instance_admin_granted' : 'account.instance_admin_revoked', null, ['email' => $account->email]);
        $this->info($grant ? "{$account->email} is now an instance admin." : "{$account->email} is no longer an instance admin.");

        return self::SUCCESS;
    }
}
