<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Auth;

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * Email + password logins (MULTI_WAREHOUSE.md §6): the credentials are checked against the central account,
 * then the login resolves to *this warehouse's* person through `memberships` — never through
 * people.account_id, which a restored dump could have pointed anywhere. Auth::id() stays the person id.
 *
 * "No such account", "not a member here", "locked" and "wrong password" all fail the same way, and each
 * costs one password hash, so a guesser can't tell them apart (by answer or by timing).
 */
class AccountUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials)
    {
        $account = Account::findByEmail($credentials['email'] ?? null);
        $person = $account && ! $account->isLocked() ? $this->personFor($account) : null;

        if (! $person && array_key_exists('password', $credentials)) {
            $this->burnHash((string) $credentials['password']);
        }

        return $person;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        $hash = $user->getAuthPassword();

        return $hash !== null && Hash::check((string) ($credentials['password'] ?? ''), $hash);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false)
    {
        $hash = $user->getAuthPassword();
        if ($hash !== null && ($force || Hash::needsRehash($hash))) {
            // Through the account directly: a rehash isn't a password change, so it must not end sessions.
            Account::whereKey($user->account_id)->update(['password' => Hash::make($credentials['password'])]);
        }
    }

    /** This warehouse's person for an account, if it has an active membership here. */
    public function personFor(Account $account): ?Authenticatable
    {
        $membership = Membership::where('account_id', $account->id)
            ->where('warehouse_id', app(WarehouseContext::class)->current()->id)
            ->where('status', Membership::ACTIVE)
            ->first();

        $person = $membership ? $this->createModel()->newQuery()->find($membership->person_id) : null;

        // The cached link must agree with memberships; if a restore changed it, the login doesn't resolve.
        return $person && (int) $person->account_id === (int) $account->id ? $person->setRelation('account', $account) : null;
    }

    private function burnHash(string $password): void
    {
        static $dummy;
        Hash::check($password, $dummy ??= Hash::make('not-a-real-password'));
    }
}
