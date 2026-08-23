<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\PeopleRoles;
use App\Models\Permission;
use App\Models\Person;
use Illuminate\Support\Collection;

/**
 * Shared between PeopleController (party roles: Partner/Donor/Volunteer)
 * and UserAdminController (login-capable staff/partner accounts) — both
 * controllers let an acting user assign roles/permission overrides to a
 * Person, and the "you can't grant or touch what you don't hold yourself"
 * escalation guard is identical either way. Extracted rather than
 * duplicated so the two controllers can't quietly drift apart on this.
 */
class PersonPermissionAssignment
{
    /**
     * The permission keys a set of role IDs plus per-person overrides would
     * grant, in either direction. Used both to compute what to actually
     * store and to check the acting user isn't granting something they
     * don't hold themselves.
     */
    public function resolveEffectiveKeys(array $roleIds, array $permissionOverrides): Collection
    {
        $keys = Permission::whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))->pluck('key');

        $overridden = Permission::whereIn('id', array_column($permissionOverrides, 'permission_id'))
            ->get()->keyBy('id');

        foreach ($permissionOverrides as $override) {
            $permission = $overridden[$override['permission_id']];
            $keys = $override['granted']
                ? $keys->push($permission->key)
                : $keys->reject(fn ($key) => $key === $permission->key);
        }

        return $keys->unique()->values();
    }

    /**
     * You cannot grant (via a role or a per-person override) a permission
     * you do not hold yourself — and, for edits, you cannot modify a person
     * who currently holds a permission you don't have, even if the edit
     * itself doesn't touch that permission.
     *
     * $actingUser is really App\Models\User (what Auth::user() returns) —
     * not typed as such because User and Person are two separate Eloquent
     * models over the same 'people' table, sharing permission logic via
     * the HasPermissions trait rather than a common class.
     */
    public function assertNoEscalation($actingUser, ?Person $existingTarget, array $newRoleIds, array $newOverrides): ?string
    {
        $actingKeys = collect($actingUser->effectivePermissionKeys());

        if ($existingTarget) {
            $currentKeys = collect($existingTarget->effectivePermissionKeys());
            if ($currentKeys->diff($actingKeys)->isNotEmpty()) {
                return 'You cannot modify a person who holds a permission you do not have yourself.';
            }
        }

        $resultingKeys = $this->resolveEffectiveKeys($newRoleIds, $newOverrides);
        $notAllowed = $resultingKeys->diff($actingKeys);

        if ($notAllowed->isNotEmpty()) {
            return 'You cannot grant permissions you do not have yourself: '.$notAllowed->implode(', ');
        }

        return null;
    }

    public function syncRolesAndPermissions(Person $person, array $roleData, array $permissionData): void
    {
        PeopleRoles::where('person_id', $person->id)->delete();
        if (! empty($roleData)) {
            PeopleRoles::insert(array_map(fn ($role) => [
                'person_id' => $person->id,
                'role_id' => $role['role_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $roleData));
        }

        $person->person_permissions()->sync(collect($permissionData)->mapWithKeys(
            fn ($override) => [$override['permission_id'] => ['granted' => $override['granted']]]
        ));
    }
}
