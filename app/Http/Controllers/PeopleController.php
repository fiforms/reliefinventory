<?php

namespace App\Http\Controllers;

use App\Models\PeopleRoles;
use App\Models\Permission;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class PeopleController extends Controller
{
    // Validation rules for people
    //
    // A disaster-response donation often arrives with less than a full
    // contact: sometimes only an organization name is known ("came from
    // Walmart, no contact given"), sometimes not even that. Neither name
    // field is unconditionally required — but at least one of first_name,
    // last_name, or organization must be given, so a person record always
    // carries *some* identifying information.
    private const VALIDATION_RULES = [
        'first_name' => 'nullable|required_without_all:last_name,organization|string|max:255',
        'last_name' => 'nullable|required_without_all:first_name,organization|string|max:255',
        'organization' => 'nullable|string|max:255',
        'phone' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255|unique:people,email',
        'address' => 'nullable|string',
        'city' => 'nullable|string|max:255',
        'state' => 'nullable|string|max:2',
        'zip' => 'nullable|string|max:10',
        'county_id' => 'nullable|exists:counties,id',
        'comments' => 'nullable|string',
        // Admin-assigned physical badge identifier for PIN-unlock badge
        // scanning — see HasPinLogin / UnlockController.
        'badge_code' => 'nullable|string|max:255|unique:people,badge_code',
        'people_roles' => 'nullable|array',
        'people_roles.*.role_id' => 'required|exists:roles,id',
        'person_permissions' => 'nullable|array',
        'person_permissions.*.permission_id' => 'required|exists:permissions,id',
        'person_permissions.*.granted' => 'required|boolean',
    ];

    /**
     * Retrieve all people (customers and donors).
     *
     * @return JsonResponse
     */
    public function index()
    {
        $people = Person::with(['people_roles', 'roles', 'county', 'person_permissions'])->get();

        // Reshape the pivot-bearing relation into the flat {permission_id, granted}
        // form the frontend reads and submits, so read/write use the same shape.
        $people->each(function ($person) {
            $person->setRelation('person_permissions', $person->person_permissions->map(fn ($p) => [
                'permission_id' => $p->id,
                'granted' => (bool) $p->pivot->granted,
            ])->values());
        });

        return response()->json([
            'records' => $people,
            'templates' => [
                '_default' => [
                    'first_name' => '',
                    'last_name' => '',
                    'organization' => '',
                    'phone' => '',
                    'email' => '',
                    'address' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => '',
                    'county_id' => null,
                    'comments' => '',
                    'badge_code' => '',
                    'people_roles' => [],
                    'person_permissions' => [],
                ],
                'people_roles' => [
                    'role_id' => null,
                ],
                'person_permissions' => [
                    'permission_id' => null,
                    'granted' => true,
                ],
            ],
        ]);
    }

    /**
     * The permission keys a set of role IDs plus per-person overrides would
     * grant, in either direction. Used both to compute what to actually
     * store and to check the acting user isn't granting something they
     * don't hold themselves.
     */
    private function resolveEffectiveKeys(array $roleIds, array $permissionOverrides): Collection
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
     * itself doesn't touch that permission. Mirrors the pre-permissions
     * bitwise check this replaces, just expressed in permission-key terms.
     */
    /**
     * $actingUser is really App\Models\User (what Auth::user() returns) —
     * not typed as such because User and Person are two separate Eloquent
     * models over the same 'people' table, sharing permission logic via
     * the HasPermissions trait rather than a common class.
     */
    private function assertNoEscalation($actingUser, ?Person $existingTarget, array $newRoleIds, array $newOverrides): ?string
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

    private function syncRolesAndPermissions(Person $person, array $roleData, array $permissionData): void
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

    /**
     * Store a new person (customer or donor).
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $roleData = $data['people_roles'] ?? [];
        $permissionData = $data['person_permissions'] ?? [];

        if ($error = $this->assertNoEscalation(Auth::user(), null, array_column($roleData, 'role_id'), $permissionData)) {
            return response()->json(['message' => $error], 403);
        }

        $person = Person::create($data);
        $this->syncRolesAndPermissions($person, $roleData, $permissionData);

        return response()->json([
            'message' => 'Person added successfully.',
            'record' => $person,
        ], 201);
    }

    /**
     * Update an existing person.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $person = Person::findOrFail($id);

        $rules = self::VALIDATION_RULES;
        $rules['email'] = 'nullable|email|max:255|unique:people,email,'.$id;
        $rules['badge_code'] = 'nullable|string|max:255|unique:people,badge_code,'.$id;

        $data = $request->validate($rules);
        $roleData = $data['people_roles'] ?? [];
        $permissionData = $data['person_permissions'] ?? [];

        if ($error = $this->assertNoEscalation(Auth::user(), $person, array_column($roleData, 'role_id'), $permissionData)) {
            return response()->json(['message' => $error], 403);
        }

        $person->update($data);
        $this->syncRolesAndPermissions($person, $roleData, $permissionData);

        return response()->json([
            'message' => 'Person updated successfully.',
        ], 200);
    }

    /**
     * Delete a person.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $person = Person::findOrFail($id);

        if ($person->isSystem()) {
            return response()->json([
                'message' => 'This is a system-provided record and cannot be deleted.',
            ], 422);
        }

        $person->delete();

        return response()->json([
            'message' => 'Person deleted successfully.',
        ], 200);
    }
}
