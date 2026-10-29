<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * This instance's local record of one trusted peer (another instance or a client app). The peer's public
 * key is pinned here; a changed key is never accepted silently (see TrustService::enroll). Grants are
 * read live on every request, so editing or revoking takes effect at once.
 */
class Trust extends Model
{
    // `backup_client` is the RI Launcher (or any operator tool) that pulls backups and, later, reports. It is a
    // client app that signs its own requests, but not a managed device: it has no location, heartbeat or
    // browser session, so it is deliberately not in CLIENT_KINDS.
    public const KINDS = ['instance', 'print_client', 'rstation', 'backup_client'];

    public const CLIENT_KINDS = ['print_client', 'rstation'];

    protected $fillable = ['identity_id', 'kind', 'peer_uuid', 'name', 'public_key', 'base_url', 'status', 'auth_method'];

    // session_epoch is bumped only by TrustService, never mass-assigned.

    protected $casts = [
        'approved_at' => 'datetime',
        'domain_verified_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function grants()
    {
        return $this->hasMany(TrustGrant::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(Person::class, 'approved_by_person_id');
    }

    public function managedDevice()
    {
        return $this->hasOne(ManagedDevice::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isClient(): bool
    {
        return in_array($this->kind, self::CLIENT_KINDS, true);
    }

    /** @return list<string> */
    public function inboundPermissions(): array
    {
        return $this->grants->where('direction', 'inbound')->pluck('permission')->values()->all();
    }

    public function allows(string $permission): bool
    {
        return $this->grants()->where('direction', 'inbound')->where('permission', $permission)->exists();
    }
}
