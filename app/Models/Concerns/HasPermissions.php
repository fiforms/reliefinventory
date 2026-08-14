<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Concerns;

use App\Models\PeopleRoles;
use App\Models\Permission;
use App\Models\Role;

/**
 * Shared between Person (the general party record) and User (the auth
 * actor Auth::user() returns) — both models map to the same 'people' table
 * and the same row for a logged-in staff member, so permission resolution
 * has to work identically from either one. See granular-permissions-model.md.
 */
trait HasPermissions
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'people_roles', 'person_id', 'role_id')
            ->withTimestamps();
    }

    public function people_roles()
    {
        return $this->hasMany(PeopleRoles::class, 'person_id');
    }

    /**
     * Per-person permission overrides. "granted" (pivot) is true to add a
     * capability beyond this person's roles, or false to explicitly revoke
     * one a role would otherwise grant. Named to match the JSON key the
     * frontend expects (mirrors the people_roles relation naming pattern).
     */
    public function person_permissions()
    {
        return $this->belongsToMany(Permission::class, 'person_permissions', 'person_id', 'permission_id')
            ->withPivot('granted')
            ->withTimestamps();
    }

    /**
     * Every permission key this person's roles grant by default, before
     * per-person overrides are applied.
     */
    public function rolePermissionKeys(): array
    {
        return Permission::whereHas('roles', function ($query) {
            $query->whereIn('roles.id', $this->roles()->pluck('roles.id'));
        })->pluck('key')->all();
    }

    /**
     * This person's effective permission set: role defaults, with explicit
     * per-person overrides applied on top in either direction.
     */
    public function effectivePermissionKeys(): array
    {
        $keys = collect($this->rolePermissionKeys());

        foreach ($this->person_permissions()->get() as $permission) {
            if ($permission->pivot->granted) {
                $keys->push($permission->key);
            } else {
                $keys = $keys->reject(fn ($key) => $key === $permission->key);
            }
        }

        return $keys->unique()->values()->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->effectivePermissionKeys(), true);
    }
}
