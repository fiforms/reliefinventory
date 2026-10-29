<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Accounts\Membership;
use App\Models\Control\InstanceAuditLog;
use App\Models\PeopleRoles;
use App\Services\FirstRunService;
use App\Services\Warehouses\WarehouseAccounts;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Make a login an Administrator of a warehouse that is already set up: the rescue for a warehouse nobody can administer any more, or the way to
 * put an existing login into one. It is the operator's own act (an instance admin can run anything on the host), so it joins the login directly
 * rather than by invitation; the invitation rule is for backups and warehouse admins, who must not attach other people's logins (§6). Audited.
 */
class WarehouseAdmin extends Command
{
    use InstanceCommand;

    protected $signature = 'warehouse:admin {warehouse : the warehouse id} {email : the login (account) email; `account:create` makes one}
        {--first= : First name, when the warehouse has no person with this email yet}
        {--last= : Last name, when the warehouse has no person with this email yet}';

    protected $description = 'Make an existing login an Administrator of a set-up warehouse (joining it if it is not a member).';

    public function handle(WarehouseContext $context): int
    {
        if (! $warehouse = $this->warehouseOrNull($this->argument('warehouse'))) {
            return self::FAILURE;
        }
        if (! $account = $this->accountOrNull($this->argument('email'))) {
            return self::FAILURE;
        }
        if (! in_array($warehouse->status, ['active', 'suspended', 'maintenance'], true)) {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}. A warehouse that is not set up yet gets its first administrator from its setup code (`warehouse:setup-code {$warehouse->id}`).");

            return self::FAILURE;
        }

        try {
            $what = $context->run($warehouse, function () use ($warehouse, $account) {
                $membership = Membership::where('account_id', $account->id)->where('warehouse_id', $warehouse->id)->first();
                if ($membership) {
                    PeopleRoles::firstOrCreate(['person_id' => $membership->person_id, 'role_id' => FirstRunService::ADMINISTRATOR_ROLE_ID]);

                    return 'already belonged to it; now an Administrator';
                }
                if (! DB::table('people')->where('email', $account->email)->exists() && (! $this->option('first') || ! $this->option('last'))) {
                    throw new RuntimeException('That login has no person in this warehouse yet: give --first and --last for the new person.');
                }
                app(WarehouseAccounts::class)->attachRestorer($account, (string) $this->option('first'), (string) $this->option('last'));

                return 'joined it as an Administrator';
            });
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        InstanceAuditLog::record('warehouse.admin_added', $warehouse, ['email' => $account->email]);
        $this->components->info("{$account->email} {$what} in {$warehouse->display_name}.");

        return self::SUCCESS;
    }
}
