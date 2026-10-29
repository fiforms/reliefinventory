<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * A login, shared by every warehouse it is a member of (MULTI_WAREHOUSE.md §6). Only credentials live here;
 * everything about what the login may do in a warehouse is that warehouse's person (people.account_id).
 *
 * Never mass-assign `is_instance_admin`: it is set only by the instance console or the CLI.
 */
class Account extends Model
{
    protected $connection = 'accounts';

    protected $fillable = ['email'];

    /** The columns' defaults, so a new account has them before it is ever read back (auth_epoch is compared strictly). */
    protected $attributes = [
        'is_instance_admin' => false,
        'auth_epoch' => 0,
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'locked_at' => 'datetime',
        'is_instance_admin' => 'boolean',
        'auth_epoch' => 'integer',
    ];

    protected static function booted(): void
    {
        // A new password or a lock ends every session the account has, in every warehouse (§6).
        static::updating(function (self $account) {
            if ($account->isDirty('password') || ($account->isDirty('locked_at') && $account->locked_at)) {
                $account->auth_epoch = (int) $account->getOriginal('auth_epoch') + 1;
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public static function findByEmail(?string $email): ?self
    {
        // The column's collation makes this (and the unique index) case-insensitive.
        return $email === null ? null : self::where('email', trim($email))->first();
    }

    /**
     * The account for an email and password, or null: it must exist, be unlocked and verified, and have that password. For
     * steps that need the owner to prove who they are (accepting an invitation, joining a new warehouse as its administrator).
     */
    public static function verified(string $email, string $password): ?self
    {
        $account = self::findByEmail($email);
        if (! $account || $account->isLocked() || ! $account->password || ! $account->email_verified_at
            || ! Hash::check($password, $account->password)) {
            return null;
        }

        return $account;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * The sole-membership rule (§6): $warehouseId is this account's only home if every other membership is
     * in a training warehouse. An account whose only memberships are training ones has no real home, so its
     * training warehouses count as home. Computed from memberships only, never from people.account_id.
     */
    public function isSoleHome(string $warehouseId): bool
    {
        return $this->memberships()->where('warehouse_id', $warehouseId)->exists()
            && ! $this->memberships()->where('warehouse_id', '!=', $warehouseId)->where('is_training', false)->exists();
    }
}
