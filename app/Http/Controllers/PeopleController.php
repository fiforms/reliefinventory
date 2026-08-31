<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\PersonPermissionAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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
        'is_organization' => 'nullable|boolean',
        'parent_person_id' => 'nullable|exists:people,id',
        'contact_role' => 'nullable|string|max:100',
        'category_id' => 'nullable|exists:person_categories,id',
        'phone' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255|unique:people,email',
        'address' => 'nullable|string',
        'city' => 'nullable|string|max:255',
        'state' => 'nullable|string|max:2',
        'zip' => 'nullable|string|max:10',
        'county_id' => 'nullable|exists:counties,id',
        // Not persisted directly — see applyAddressVerification(). Just an
        // intent signal, validated here for shape only.
        'verified_address' => 'nullable|boolean',
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
     * Retrieve all people (partners and donors).
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $query = Person::with(['people_roles', 'roles', 'county', 'person_permissions', 'parent', 'category', 'partnerStatusLogs.changedBy']);

        // Lets a Parent Organization picker (SearchSelect) fetch only org
        // records, via a distinct cached URL — see People.vue.
        if ($request->boolean('is_organization')) {
            $query->where('is_organization', true);
        }

        // Lets a shipment Contact Person picker (SearchSelect) fetch only the
        // contacts under one org, via a distinct cached URL — see
        // Receiving.vue's contact-person field.
        if ($request->filled('parent_person_id')) {
            $query->where('parent_person_id', $request->integer('parent_person_id'));
        }

        $people = $query->get();

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
                    'is_organization' => false,
                    'parent_person_id' => null,
                    'contact_role' => '',
                    'category_id' => null,
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
     * Store a new person (partner or donor).
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
        $this->applyAddressVerification($request, $person, addressChanged: false);

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

        if (isset($data['parent_person_id']) && (int) $data['parent_person_id'] === (int) $id) {
            return response()->json(['message' => 'A person cannot be their own parent organization.'], 422);
        }

        if ($error = $this->permissionAssignment->assertNoEscalation(Auth::user(), $person, array_column($roleData, 'role_id'), $permissionData)) {
            return response()->json(['message' => $error], 403);
        }

        $addressChanged = collect(['address', 'city', 'state', 'zip'])
            ->contains(fn ($field) => array_key_exists($field, $data) && $data[$field] != $person->$field);

        $person->update($data);
        $this->permissionAssignment->syncRolesAndPermissions($person, $roleData, $permissionData);
        $this->applyAddressVerification($request, $person, $addressChanged);

        return response()->json([
            'message' => 'Person updated successfully.',
            'record' => $person->fresh(),
        ], 200);
    }

    /**
     * `address_verified_at` is deliberately never in $fillable — trusting a
     * raw client-supplied timestamp would make it meaningless. Instead the
     * client sends a plain `verified_address` boolean *intent* (set once a
     * geocode lookup has actually run and been accepted, see
     * OrderEntry.vue/People.vue's maybeAutoLookupCounty), and this decides
     * what to do with it: stamp `now()` if asserted, or — the case that
     * actually matters for correctness — clear it whenever the address
     * itself changed without that assertion, so a stale "verified" flag
     * can never survive an edit that wasn't itself verified.
     */
    private function applyAddressVerification(Request $request, Person $person, bool $addressChanged): void
    {
        if ($request->boolean('verified_address')) {
            $person->address_verified_at = now();
        } elseif ($addressChanged) {
            $person->address_verified_at = null;
        } else {
            return;
        }
        $person->save();
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

    /**
     * Move a Partner-tagged person's ongoing partner_status (approve/deny/
     * block/reconsider) — see Person::transitionPartnerStatus() for the
     * legal-move table and audit log. Separate from the plain field-editing
     * update() above since every transition needs to be logged, same
     * reasoning as DonationOffer/FormSubmission's dedicated transition
     * endpoints rather than a raw field write.
     */
    public function partnerStatus(Request $request, $id)
    {
        $person = Person::findOrFail($id);

        $data = $request->validate([
            'to_status' => ['required', Rule::in([
                Person::PARTNER_STATUS_PENDING,
                Person::PARTNER_STATUS_APPROVED,
                Person::PARTNER_STATUS_DENIED,
                Person::PARTNER_STATUS_BLOCKED,
            ])],
            'notes' => 'nullable|string',
        ]);

        try {
            $person->transitionPartnerStatus($data['to_status'], Auth::id(), $data['notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['record' => $person->fresh(['partnerStatusLogs.changedBy'])]);
    }
}
