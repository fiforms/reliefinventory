<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\PersonPermissionAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PeopleController extends Controller
{
    public function __construct(private PersonPermissionAssignment $permissionAssignment) {}

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
        // A fact about the person, not a role/permission — see Person::$fillable.
        'is_volunteer' => 'nullable|boolean',
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
                    'is_volunteer' => false,
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
     * Store a new person (customer or donor).
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);
        $roleData = $data['people_roles'] ?? [];
        $permissionData = $data['person_permissions'] ?? [];

        if ($error = $this->permissionAssignment->assertNoEscalation(Auth::user(), null, array_column($roleData, 'role_id'), $permissionData)) {
            return response()->json(['message' => $error], 403);
        }

        $person = Person::create($data);
        $this->permissionAssignment->syncRolesAndPermissions($person, $roleData, $permissionData);

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

        if ($error = $this->permissionAssignment->assertNoEscalation(Auth::user(), $person, array_column($roleData, 'role_id'), $permissionData)) {
            return response()->json(['message' => $error], 403);
        }

        $person->update($data);
        $this->permissionAssignment->syncRolesAndPermissions($person, $roleData, $permissionData);

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
