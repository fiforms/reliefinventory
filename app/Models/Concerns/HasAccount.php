<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Concerns;

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\User;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A person's login lives in the central accounts schema (MULTI_WAREHOUSE.md §6). Shared by Person and User
 * (same `people` row) so that every existing read and write of `password` and `email_verified_at` keeps
 * working: both are virtual attributes backed by the account, written to it when the person is saved, and
 * `email` is mirrored into it.
 *
 * Saving a person creates its account (and this warehouse's membership) when credentials are set on a
 * person without one, or when a User is created. Trainees never get one: they are passwordless tap-logins.
 *
 * Guarded here rather than in each controller: an account that is also used in another warehouse can't have
 * its email changed from this one, and a new person can't take over an email that already has an account.
 */
trait HasAccount
{
    /** Credential changes waiting for the person row to be saved. */
    private array $pendingAccount = [];

    /**
     * Overrides rather than model events: credentials must be written however the save happens, and
     * Event::fake() or withoutEvents() would silently skip an event listener.
     */
    public function save(array $options = [])
    {
        $this->guardAccountChanges();
        $saved = parent::save($options);
        if ($saved) {
            $this->writeAccount();
        }

        return $saved;
    }

    public function delete()
    {
        $deleted = parent::delete();
        if ($deleted) {
            $this->leaveWarehouse();
        }

        return $deleted;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function hasAccount(): bool
    {
        return $this->account_id !== null;
    }

    public function isInstanceAdmin(): bool
    {
        return (bool) $this->account?->is_instance_admin;
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->account?->password,
            set: function ($value) {
                $this->pendingAccount['password'] = $value === null || Hash::isHashed($value) ? $value : Hash::make($value);

                return [];
            },
        )->withoutObjectCaching();
    }

    protected function emailVerifiedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->account?->email_verified_at,
            set: function ($value) {
                $this->pendingAccount['email_verified_at'] = $value;

                return [];
            },
        )->withoutObjectCaching();
    }

    /** Whether saving this person should create an account it doesn't have yet. */
    private function needsNewAccount(): bool
    {
        if ($this->account_id || $this->is_trainee || ! $this->email) {
            return false;
        }

        // A new User is a login by definition; anyone else only once credentials are set on them.
        return $this->pendingAccount !== [] || ($this instanceof User && ! $this->exists);
    }

    private function guardAccountChanges(): void
    {
        if ($this->needsNewAccount() && Account::findByEmail($this->email)) {
            throw ValidationException::withMessages(['email' => 'This email address is already used to log in.']);
        }

        if (! $this->account_id) {
            return;
        }

        $account = Account::find($this->account_id);
        $changes = $this->loginChanges($account);
        if ($changes === []) {
            return;
        }

        if (array_key_exists('email', $changes)) {
            if (! $this->email) {
                throw ValidationException::withMessages(['email' => 'A person who can log in needs an email address.']);
            }
            $taken = Account::findByEmail($this->email);
            if ($taken && ! $taken->is($account)) {
                throw ValidationException::withMessages(['email' => 'This email address is already used to log in.']);
            }
        }

        // Someone signed in changing another person's login: only where this warehouse is its sole home (§6).
        // The owner themselves, a reset-token or verify link, and the CLI are not limited by this.
        if ($account && Auth::check() && (int) Auth::id() !== (int) $this->id
            && ! $account->isSoleHome(app(WarehouseContext::class)->current()->id)) {
            throw ValidationException::withMessages([array_key_first($changes) => 'This login is also used in another '
                .'warehouse, so only its owner can change it. You can send them a password reset email, or deactivate them here.']);
        }
    }

    /** What saving would actually change about the login (re-stamping a verified address changes nothing). */
    private function loginChanges(?Account $account): array
    {
        $changes = [];
        if ($this->isDirty('email')) {
            $changes['email'] = $this->email;
        }
        if (array_key_exists('password', $this->pendingAccount) && $this->pendingAccount['password'] !== $account?->password) {
            $changes['password'] = true;
        }
        if (array_key_exists('email_verified_at', $this->pendingAccount)
            && ($this->pendingAccount['email_verified_at'] === null) !== ($account?->email_verified_at === null)) {
            $changes['email_verified_at'] = true;
        }

        return $changes;
    }

    private function writeAccount(): void
    {
        $pending = $this->pendingAccount;
        $this->pendingAccount = [];

        if ($this->is_trainee) {
            return;
        }

        if ($this->needsNewAccountAfterSave($pending)) {
            $account = new Account(['email' => $this->email]);
            $account->forceFill($pending)->save();

            $warehouse = app(WarehouseContext::class)->current();
            Membership::create([
                'account_id' => $account->id,
                'warehouse_id' => $warehouse->id,
                'person_id' => $this->id,
                'status' => Membership::ACTIVE,
                'is_training' => $warehouse->is_training,
            ]);

            $this->account_id = $account->id;
            $this->saveQuietly();
            $this->setRelation('account', $account);

            return;
        }

        if (! $this->account_id) {
            return;
        }

        $account = $this->account;
        if ($this->wasChanged('email')) {
            $pending['email'] = $this->email;
        }
        if ($account && $pending !== []) {
            $account->forceFill($pending);
            // Re-stamping an address that is already verified keeps the original time.
            if ($account->isDirty('email_verified_at') && $account->getOriginal('email_verified_at') && $account->email_verified_at) {
                $account->email_verified_at = $account->getOriginal('email_verified_at');
            }
            $account->save();
        }
    }

    /** needsNewAccount(), re-asked after the insert (the pending changes were consumed already). */
    private function needsNewAccountAfterSave(array $pending): bool
    {
        if ($this->account_id || ! $this->email) {
            return false;
        }

        return $pending !== [] || ($this instanceof User && $this->wasRecentlyCreated);
    }

    /**
     * This person stops being a login here; the person row stays. Used by "delete my account" (Profile).
     */
    public function detachAccount(): void
    {
        $this->leaveWarehouse();
        $this->forceFill(['account_id' => null])->saveQuietly();
        $this->unsetRelation('account');
    }

    /**
     * Leaving this warehouse (a deleted person, or detachAccount) removes its membership. An account left with
     * no warehouse at all goes too, unless it is an instance admin (§6, "Removing a warehouse" applies the
     * same test).
     */
    private function leaveWarehouse(): void
    {
        if (! $this->account_id) {
            return;
        }

        Membership::where('account_id', $this->account_id)
            ->where('warehouse_id', app(WarehouseContext::class)->current()->id)
            ->delete();

        $account = Account::find($this->account_id);
        if ($account && ! $account->is_instance_admin && ! $account->memberships()->exists()) {
            $account->delete();
        }
    }
}
