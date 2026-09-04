<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\PersonPermissionAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/**
 * User Administration (TODO.md item 1) — create/promote/deactivate
 * login-capable accounts (anyone with a non-null email). Distinct from
 * PeopleController: that page manages party-tracking roles
 * (Partner/Donor, plus the is_volunteer flag — see Person::$fillable),
 * no permission overrides; this one manages the login-capable roles
 * (Administrator, Sorting and Inventory, Office, Partner) and
 * per-person permission overrides. Whether an account belongs to a
 * volunteer is independent of which of these roles it holds.
 * Shares the escalation-guard/role-sync logic with PeopleController via
 * PersonPermissionAssignment rather than duplicating it.
 */
class UserAdminController extends Controller
{
    public function __construct(private PersonPermissionAssignment $permissionAssignment) {}

    private const VALIDATION_RULES = [
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:people,email',
        // A fact about the person, not a role/permission — a volunteer
        // can just as easily be the office manager or an administrator.
        'is_volunteer' => 'nullable|boolean',
        'people_roles' => 'nullable|array',
        'people_roles.*.role_id' => 'required|exists:roles,id',
        'person_permissions' => 'nullable|array',
        'person_permissions.*.permission_id' => 'required|exists:permissions,id',
        'person_permissions.*.granted' => 'required|boolean',
    ];

    /**
     * Retrieve all login-capable people (anyone with an email set).
     *
     * @return JsonResponse
     */
    public function index()
    {
        $people = Person::whereNotNull('email')
            ->with(['people_roles', 'roles', 'person_permissions'])
            ->get();

        $people->each(function ($person) {
            $person->setRelation('person_permissions', $person->person_permissions->map(fn ($p) => [
                'permission_id' => $p->id,
                'granted' => (bool) $p->pivot->granted,
            ])->values());

            // password itself is hidden from serialization (Person::$hidden)
            // — this exposes just enough to distinguish "invited, hasn't set
            // a password yet" from "active" in the UI.
            $person->has_password = ! is_null($person->password);
        });

        return response()->json([
            'records' => $people,
            'templates' => [
                '_default' => [
                    'first_name' => '',
                    'last_name' => '',
                    'email' => '',
                    'is_volunteer' => false,
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
     * Create a new login-capable account and email them a "set your
     * password" link (reuses Laravel's stock password-reset broker/route
     * rather than a separate invite-token system).
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

        $person = Person::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'is_volunteer' => $data['is_volunteer'] ?? false,
            // The admin creating this account is vouching for the email
            // address themselves — equivalent trust to typing it in
            // directly. Without this, the new account would be silently
            // blocked by AuthenticatedSessionController's MustVerifyEmail
            // check the first time they try to log in after setting a
            // password, with no way to self-resend a verification email
            // (they've never been authenticated to reach that flow).
            'email_verified_at' => now(),
            // Same vouching covers approval too — an admin-created account
            // was never self-registered, so it shouldn't land in the
            // pending-approval gate EnsureAccountApproved enforces.
            'approved_at' => now(),
        ]);
        $this->permissionAssignment->syncRolesAndPermissions($person, $roleData, $permissionData);

        Password::sendResetLink(['email' => $person->email]);

        return response()->json([
            'message' => 'User created. An email has been sent so they can set their password.',
            'record' => $person,
        ], 201);
    }

    /**
     * Promote/change an existing login-capable account's roles and
     * permission overrides.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $person = Person::whereNotNull('email')->findOrFail($id);

        $rules = self::VALIDATION_RULES;
        $rules['email'] = 'required|email|max:255|unique:people,email,'.$id;

        $data = $request->validate($rules);
        $roleData = $data['people_roles'] ?? [];
        $permissionData = $data['person_permissions'] ?? [];

        if ($error = $this->permissionAssignment->assertNoEscalation(Auth::user(), $person, array_column($roleData, 'role_id'), $permissionData)) {
            return response()->json(['message' => $error], 403);
        }

        $person->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'is_volunteer' => $data['is_volunteer'] ?? false,
        ]);
        $this->permissionAssignment->syncRolesAndPermissions($person, $roleData, $permissionData);

        return response()->json(['message' => 'User updated successfully.'], 200);
    }

    /**
     * Block login without deleting the account or its history.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function deactivate($id)
    {
        $person = Person::whereNotNull('email')->findOrFail($id);

        if ($person->id === Auth::id()) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $actingKeys = collect(Auth::user()->effectivePermissionKeys());
        $targetKeys = collect($person->effectivePermissionKeys());
        if ($targetKeys->diff($actingKeys)->isNotEmpty()) {
            return response()->json(['message' => 'You cannot deactivate a person who holds a permission you do not have yourself.'], 403);
        }

        $person->update(['disabled_at' => now()]);

        return response()->json(['message' => 'User deactivated.'], 200);
    }

    /**
     * Restore login access for a previously deactivated account.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function reactivate($id)
    {
        $person = Person::whereNotNull('email')->findOrFail($id);

        $person->update([
            'disabled_at' => null,
            // Admin override for a self-registered account still pending
            // email verification (offline mode, or a broken mail provider
            // self-verification can't route around) — same vouching as
            // store() does for a brand-new admin-created account, so this
            // person isn't immediately re-blocked by MustVerifyEmail.
            'email_verified_at' => $person->email_verified_at ?? now(),
            // An explicit admin action here is itself an approval — don't
            // leave a self-registered account stuck behind
            // EnsureAccountApproved after an admin has already vouched for
            // it via reactivate. Never overwrites an existing approval.
            'approved_at' => $person->approved_at ?? now(),
        ]);

        return response()->json(['message' => 'User reactivated.'], 200);
    }

    /**
     * Approve a self-registered account that's been reviewed — clears the
     * pending-approval gate (EnsureAccountApproved) without granting any
     * roles/permissions on its own; pair with update() to assign a role.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function approve($id)
    {
        $person = Person::whereNotNull('email')->findOrFail($id);

        $person->update([
            'approved_at' => $person->approved_at ?? now(),
            // Mirrors reactivate()'s reasoning: an admin explicitly
            // approving this account is vouching for the address too, in
            // case mail never delivered.
            'email_verified_at' => $person->email_verified_at ?? now(),
        ]);

        return response()->json(['message' => 'User approved.'], 200);
    }

    /**
     * Resend the "set your password" email — for an account that never
     * completed setup, or whoever just forgot.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function resendInvite($id)
    {
        $person = Person::whereNotNull('email')->findOrFail($id);

        Password::sendResetLink(['email' => $person->email]);

        return response()->json(['message' => 'Invite email resent.'], 200);
    }
}
