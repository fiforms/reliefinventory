<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;

/**
 * A computer paired to the INSTANCE (not to one warehouse), identified by the Ed25519 key it proved it holds when it paired. Today the only
 * thing it can hold is `instance.backup` (App\Support\InstancePermission), for the RI Launcher's whole-instance backups. Status and
 * permissions are read live on every request, so revoking takes effect at once. Created only by InstanceTrustService.
 */
class InstanceTrust extends Model
{
    protected $connection = 'control';

    protected $table = 'instance_trusts';

    // Written only by InstanceTrustService, never from request input.
    protected $guarded = [];

    protected $casts = [
        'permissions' => 'array', 'approved_at' => 'datetime', 'revoked_at' => 'datetime', 'last_seen_at' => 'datetime',
    ];

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function allows(string $permission): bool
    {
        return $this->isApproved() && in_array($permission, $this->permissions ?? [], true);
    }

    /** @return list<string> */
    public function permissionList(): array
    {
        return array_values($this->permissions ?? []);
    }
}
