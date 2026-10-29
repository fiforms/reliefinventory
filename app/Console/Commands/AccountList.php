<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Account;
use Illuminate\Console\Command;

/** Every login on the instance (the accounts schema), with who is an instance admin and who is locked (MULTI_WAREHOUSE.md §6). */
class AccountList extends Command
{
    use InstanceCommand;

    protected $signature = 'account:list {--admins : Only instance admins} {--locked : Only locked accounts}';

    protected $description = 'List the logins on this instance (instance admins, locked accounts, how many warehouses each belongs to).';

    public function handle(): int
    {
        $accounts = Account::withCount('memberships')->orderBy('email')
            ->when($this->option('admins'), fn ($q) => $q->where('is_instance_admin', true))
            ->when($this->option('locked'), fn ($q) => $q->whereNotNull('locked_at'))
            ->get();

        $this->table(['Email', 'Instance admin', 'Verified', 'Locked', 'Warehouses', 'Created'], $accounts->map(fn (Account $a) => [
            $a->email, $this->yesNo('yes', $a->is_instance_admin), $this->yesNo('yes', (bool) $a->email_verified_at),
            $a->locked_at ? $a->locked_at->format('Y-m-d') : '', $a->memberships_count, $a->created_at?->format('Y-m-d'),
        ]));
        $this->line($accounts->count().' account(s), '.$accounts->where('is_instance_admin', true)->count().' instance admin(s).');

        return self::SUCCESS;
    }
}
