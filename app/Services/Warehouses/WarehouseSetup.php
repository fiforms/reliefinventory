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
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Hands a warehouse that is awaiting its setup code to the person who holds the code (MULTI_WAREHOUSE.md §7): they become
 * its Administrator, the code is used up and the warehouse goes `active`, all or nothing. Never an instance admin.
 * Runs in the warehouse being set up (WarehouseContext).
 */
class WarehouseSetup
{
    public function __construct(private FirstRunService $firstRun, private SetupCodes $codes) {}

    /** What the setup screen asks of the person who will run the warehouse. */
    public static function personRules(bool $existingLogin): array
    {
        return [
            'login' => ['required', 'in:new,existing'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => $existingLogin ? ['required', 'string'] : ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * The login of the person setting a warehouse up by restoring a backup into it: an existing one proved by its password (wrong
     * tries count against the warehouse), or a new one made now (verified: the instance admin vouched for them by handing over
     * the code). The login is settled before the database is replaced because the form's password is only here and now.
     *
     * @param  array{login:string,email:string,password:string}  $data
     *
     * @throws ValidationException
     */
    public function identity(Warehouse $warehouse, array $data): Account
    {
        if ($data['login'] === 'existing') {
            $this->codes->guardAttempts($warehouse, 'password');
            $account = Account::verified($data['email'], $data['password']);
            if (! $account) {
                $this->codes->fail($warehouse);
                throw ValidationException::withMessages(['password' => 'That email and password do not match a login.']);
            }

            return $account;
        }
        if (Account::findByEmail($data['email'])) {
            throw ValidationException::withMessages(['email' => 'That email already has a login. Choose "I already have a login" instead.']);
        }
        $account = new Account(['email' => $data['email']]);
        $account->forceFill(['password' => Hash::make($data['password']), 'email_verified_at' => now()])->save();

        return $account;
    }

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
     * $restored: a backup was just loaded into the warehouse. It already has people, possibly Administrators and its own roles and
     * permissions, so the holder is added to them (the person in the backup with the holder's email is the holder, otherwise a
     * new person) and nothing is seeded over what the backup brought.
     *
     * @param  array{first_name:string,last_name:string}  $person
     */
    public function withExistingAccount(Warehouse $warehouse, SetupCode $code, Account $account, array $person, bool $restored = false): User
    {
        return $this->complete($warehouse, $code, function () use ($warehouse, $account, $person, $restored) {
            if ($restored && ($user = app(WarehouseAccounts::class)->adoptRestoredPerson($account))) {
                return $user;
            }
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
        }, $restored);
    }

    /**
     * The code row is locked for the whole setup, so two people holding the same code can't both finish. If anything
     * fails, nothing is kept and the code still works.
     */
    private function complete(Warehouse $warehouse, SetupCode $code, callable $makeAdministrator, bool $restored = false): User
    {
        return (new SetupCode)->getConnection()->transaction(function () use ($warehouse, $code, $makeAdministrator, $restored) {
            $locked = SetupCode::usable()->where('warehouse_id', $warehouse->id)->whereKey($code->id)->lockForUpdate()->first();
            $fresh = Warehouse::whereKey($warehouse->id)->lockForUpdate()->first();
            if (! $locked || ! $fresh?->isAwaitingSetup()) {
                throw new RuntimeException('This setup code can no longer be used.');
            }

            $user = DB::transaction(function () use ($makeAdministrator, $restored) {
                if (! $restored) {
                    if ($this->firstRun->administratorExists()) {
                        throw new RuntimeException('This warehouse already has an administrator.');
                    }
                    $this->firstRun->ensureEssentialData();
                    app(PermissionsSeeder::class)->setContainer(app())->__invoke();
                }

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
