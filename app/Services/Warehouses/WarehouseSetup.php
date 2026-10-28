<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Models\PeopleRoles;
use App\Models\User;
use App\Services\FirstRunService;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Hands a warehouse that is awaiting its setup code to the person who holds the code (MULTI_WAREHOUSE.md §7): they become
 * its Administrator, the code is used up and the warehouse goes `active`, all or nothing. Never an instance admin.
 * Runs in the warehouse being set up (WarehouseContext).
 */
class WarehouseSetup
{
    public function __construct(private FirstRunService $firstRun) {}

    /**
     * Start a brand-new login: becomes this warehouse's Administrator.
     *
     * @param  array{email:string,password:string,first_name:string,last_name:string}  $person
     */
    public function withNewAccount(Warehouse $warehouse, SetupCode $code, array $person): User
    {
        return $this->complete($warehouse, $code, fn () => $this->firstRun->createAdministrator(
            $person['email'], $person['password'], $person['first_name'], $person['last_name'], instanceAdmin: false,
        ));
    }

    /**
     * An existing login (one the person has proved they own with its password) joins as this warehouse's Administrator. Its
     * credentials are untouched.
     *
     * @param  array{first_name:string,last_name:string}  $person
     */
    public function withExistingAccount(Warehouse $warehouse, SetupCode $code, Account $account, array $person): User
    {
        return $this->complete($warehouse, $code, function () use ($warehouse, $account, $person) {
            $user = new User;
            $user->forceFill([
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'email' => $account->email,
                'account_id' => $account->id,
                'approved_at' => now(),
            ])->save();
            Membership::create([
                'account_id' => $account->id, 'warehouse_id' => $warehouse->id, 'person_id' => $user->id,
                'status' => Membership::ACTIVE, 'is_training' => $warehouse->is_training,
            ]);
            PeopleRoles::create(['person_id' => $user->id, 'role_id' => FirstRunService::ADMINISTRATOR_ROLE_ID]);

            return $user;
        });
    }

    /** Check an existing login's password: null unless it is a verified, unlocked account with that password. */
    public function verifiedAccount(string $email, string $password): ?Account
    {
        $account = Account::findByEmail($email);
        if (! $account || $account->isLocked() || ! $account->password || ! $account->email_verified_at
            || ! Hash::check($password, $account->password)) {
            return null;
        }

        return $account;
    }

    /**
     * The code row is locked for the whole setup, so two people holding the same code can't both finish. If anything
     * fails, nothing is kept and the code still works.
     */
    private function complete(Warehouse $warehouse, SetupCode $code, callable $makeAdministrator): User
    {
        return (new SetupCode)->getConnection()->transaction(function () use ($warehouse, $code, $makeAdministrator) {
            $locked = SetupCode::usable()->where('warehouse_id', $warehouse->id)->whereKey($code->id)->lockForUpdate()->first();
            $fresh = Warehouse::whereKey($warehouse->id)->lockForUpdate()->first();
            if (! $locked || ! $fresh?->isAwaitingSetup()) {
                throw new RuntimeException('This setup code can no longer be used.');
            }

            $user = DB::transaction(function () use ($makeAdministrator) {
                if ($this->firstRun->administratorExists()) {
                    throw new RuntimeException('This warehouse already has an administrator.');
                }
                $this->firstRun->ensureEssentialData();
                app(PermissionsSeeder::class)->setContainer(app())->__invoke();

                $user = $makeAdministrator();
                $this->firstRun->markComplete();

                return $user;
            });

            $locked->forceFill(['used_at' => now()])->save();
            $fresh->forceFill(['status' => 'active'])->save();

            return $user;
        });
    }
}
