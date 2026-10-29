<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Make a login (an account, verified, with a password) that belongs to no warehouse yet, optionally an instance admin: how the first person who
 * runs a new instance is made when no wizard will make them. Belonging to a warehouse is separate (`warehouse:admin`, or a setup code).
 */
class AccountCreate extends Command
{
    use InstanceCommand;

    protected $signature = 'account:create {email : The login email} {--instance-admin : Make it an instance admin} {--password-file= : A file holding the password (otherwise asked for)}';

    protected $description = 'Create a login account, optionally an instance admin.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('That is not an email address.');

            return self::FAILURE;
        }
        if (Account::findByEmail($email)) {
            $this->components->error("{$email} already has a login. `account:instance-admin`, `account:password` and `account:lock` change an existing one.");

            return self::FAILURE;
        }
        if (($password = $this->newPassword()) === null) {
            return self::FAILURE;
        }

        $account = new Account(['email' => $email]);
        $account->forceFill(['password' => Hash::make($password), 'email_verified_at' => now(), 'is_instance_admin' => (bool) $this->option('instance-admin')])->save();
        InstanceAuditLog::record('account.created', null, ['email' => $email, 'instance_admin' => (bool) $this->option('instance-admin')]);

        $this->components->info("Created the login {$email}".($this->option('instance-admin') ? ' (instance admin)' : '').'. It belongs to no warehouse yet: `php artisan warehouse:admin <warehouse> '.$email.'` adds it to one.');

        return self::SUCCESS;
    }
}
