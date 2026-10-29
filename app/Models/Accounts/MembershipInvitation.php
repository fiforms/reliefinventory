<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An offer to attach an existing login to a person in a warehouse; it changes nothing until the login's owner accepts
 * (MULTI_WAREHOUSE.md §6). Use App\Services\MembershipInvitations rather than this model directly.
 */
class MembershipInvitation extends Model
{
    protected $connection = 'accounts';

    public const PENDING = 'pending';

    protected $fillable = ['warehouse_id', 'person_id', 'email', 'token_hash', 'status', 'source', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
