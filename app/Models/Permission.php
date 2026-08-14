<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per distinct capability (e.g. "manage-items",
 * "approve-donation-offers"). Granted to people via role_permissions
 * (a role's default bundle) and/or person_permissions (a per-person
 * override in either direction) — see Person::effectivePermissionKeys().
 */
class Permission extends Model
{
    protected $fillable = ['key', 'name', 'description'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id')
            ->withTimestamps();
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'person_permissions', 'permission_id', 'person_id')
            ->withPivot('granted')
            ->withTimestamps();
    }
}
