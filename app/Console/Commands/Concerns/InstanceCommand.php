<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands\Concerns;

use App\Models\Accounts\Account;
use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * What the instance-management commands (`account:*`, `warehouse:*`, `instance:*`) share: finding the thing a command names, and reading a
 * new password without it ever being on a command line. They are the operator's own commands (an instance admin is assumed able to run
 * anything on the host), so none of them asks a permission question; the ones that change something write to the instance audit log.
 */
trait InstanceCommand
{
    /** The warehouse a command names (any case), or null after saying so. */
    protected function warehouseOrNull(?string $id): ?Warehouse
    {
        $warehouse = $id ? Warehouse::find(strtoupper(trim($id))) : null;
        if (! $warehouse) {
            $this->components->error('There is no warehouse '.($id ?: '(none given)').'. `php artisan warehouse:list` shows them.');
        }

        return $warehouse;
    }

    protected function accountOrNull(?string $email): ?Account
    {
        $account = Account::findByEmail($email);
        if (! $account) {
            $this->components->error('No login account has that email. `php artisan account:list` shows them; `php artisan account:create` makes one.');
        }

        return $account;
    }

    /**
     * A new password: from --password-file (for scripts) or asked for twice. Never an argument or option value, which would land in the shell history
     * and the process list. Null, after saying why, when it is missing or too weak.
     */
    protected function newPassword(): ?string
    {
        if ($file = $this->option('password-file')) {
            $password = is_readable($file) ? rtrim((string) file_get_contents($file), "\r\n") : '';
        } elseif ($this->input->isInteractive()) {
            $password = (string) $this->secret('Password');
            if ($password !== (string) $this->secret('Password again')) {
                $this->components->error('The two passwords are not the same.');

                return null;
            }
        } else {
            $this->components->error('Give the password with --password-file, or run this in a terminal.');

            return null;
        }

        $check = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($check->fails()) {
            $this->components->error($check->errors()->first('password'));

            return null;
        }

        return $password;
    }

    protected function yesNo(string $label, bool $value): string
    {
        return $value ? $label : '';
    }
}
