<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Auth;

use App\Models\Accounts\Account;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Ties a session to its account's `auth_epoch` (MULTI_WAREHOUSE.md §6): a password change, reset or lock
 * bumps the epoch, and EnsureAccountSessionCurrent ends every session — in every warehouse — that started
 * under an older one. Sessions live in each warehouse's own database, so this is how an account-wide change
 * reaches them.
 */
class AccountSession
{
    public const KEY = 'account_auth_epoch';

    /** On every login (password, PIN, remember-me, training): remember the epoch and re-mirror the email. */
    public static function started(Authenticatable $user): void
    {
        $account = $user->account_id ? $user->account : null;
        if (! $account) {
            return;
        }

        self::remember($account);

        // people.email mirrors the login email; a change made in another warehouse arrives here.
        if ($user->email !== $account->email) {
            $user->forceFill(['email' => $account->email])->saveQuietly();
        }
    }

    /** After the signed-in user changes their own password: keep *this* session, end the others. */
    public static function remember(Account $account): void
    {
        if (app()->bound('session.store') && session()->isStarted()) {
            session()->put(self::KEY, [$account->id, $account->auth_epoch]);
        }
    }

    public static function forget(): void
    {
        if (app()->bound('session.store') && session()->isStarted()) {
            session()->forget(self::KEY);
        }
    }

    public static function isCurrent(Authenticatable $user): bool
    {
        $account = $user->account_id ? $user->account : null;
        if (! $account) {
            return true;   // a trainee: no account, nothing account-wide to follow
        }

        if ($account->isLocked()) {
            return false;
        }

        // A session from before epochs existed (or one set up without a Login event, as tests do) adopts
        // the current one; any session that recorded an epoch for this account must still be on it.
        [$accountId, $epoch] = session(self::KEY) ?? [null, null];
        if ($accountId !== $account->id) {
            self::remember($account);

            return true;
        }

        return $epoch === $account->auth_epoch;
    }
}
