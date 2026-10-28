<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\Warehouse;
use Illuminate\Console\Command;

/** One login: its flags, the warehouses it belongs to and the invitations still waiting for it. */
class AccountShow extends Command
{
    use InstanceCommand;

    protected $signature = 'account:show {email : The login email}';

    protected $description = 'Show one login: instance-admin and lock state, its warehouses and its pending invitations.';

    public function handle(): int
    {
        if (! $account = $this->accountOrNull($this->argument('email'))) {
            return self::FAILURE;
        }

        $this->line("Email:           {$account->email}");
        $this->line('Instance admin:  '.($account->is_instance_admin ? 'yes' : 'no'));
        $this->line('Verified:        '.($account->email_verified_at ? $account->email_verified_at->toDateTimeString() : 'no'));
        $this->line('Locked:          '.($account->locked_at ? $account->locked_at->toDateTimeString() : 'no'));
        $this->line('Has a password:  '.($account->password ? 'yes' : 'no (set by email link or account:password)'));

        $warehouses = Warehouse::pluck('display_name', 'id');
        $this->newLine();
        $this->table(['Warehouse', 'Id', 'Person', 'Status', 'Training'], Membership::where('account_id', $account->id)->get()->map(fn (Membership $m) => [
            $warehouses[$m->warehouse_id] ?? '(removed)', $m->warehouse_id, $m->person_id, $m->status, $this->yesNo('yes', (bool) $m->is_training),
        ]));

        $pending = MembershipInvitation::where('email', $account->email)->where('status', 'pending')->get();
        if ($pending->isNotEmpty()) {
            $this->line('Invitations waiting: '.$pending->map(fn ($i) => $warehouses[$i->warehouse_id] ?? $i->warehouse_id)->implode(', '));
        }

        return self::SUCCESS;
    }
}
