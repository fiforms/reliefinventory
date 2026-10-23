<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Exceptions\AccountActionRefused;
use App\Mail\AccountCreated;
use App\Models\Person;
use App\Services\PersonPermissionAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use League\Csv\Writer;

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
 *
 * Each account action (approve, deactivate, set password, ...) is one
 * private method returning a message, or throwing AccountActionRefused —
 * the single-account routes and the bulk route both call it, so a bulk
 * run is held to exactly the same checks as doing each one by hand.
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

    /** Actions the bulk endpoint offers. */
    private const BULK_ACTIONS = ['approve', 'resend_invite', 'require_password_change', 'deactivate', 'reactivate'];

    /** Display labels for status(), in the order the page's filter lists them. */
    public const STATUS_LABELS = [
        'active' => 'Active',
        'must_change_password' => 'Active — must change password',
        'invited' => 'Invited — pending setup',
        'pending_approval' => 'Pending approval',
        'unverified' => 'Pending — email not verified',
        'deactivated' => 'Deactivated',
    ];

    /**
     * Retrieve all login-capable people (anyone with an email set).
     *
     * @return JsonResponse
     */
    public function index()
    {
        $people = $this->loadUsers();

        return response()->json([
            'records' => $people,
            'status_labels' => self::STATUS_LABELS,
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
     * The user list as a spreadsheet. `ids` (comma-separated) limits it to
     * the rows the page is showing, so whatever search/filters are applied
     * there are exactly what's exported.
     */
    public function csv(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) $id)->filter()->values();

        $people = $this->loadUsers($ids->isEmpty() ? null : $ids->all())
            ->sortBy(fn ($p) => mb_strtolower($p->last_name.' '.$p->first_name));

        $csv = Writer::fromString('');
        $csv->insertOne(['First Name', 'Last Name', 'Email', 'Roles', 'Status', 'Volunteer', 'Last Login',
            'Created', 'Created By', 'Approved', 'Approved By']);
        foreach ($people as $p) {
            $csv->insertOne([
                $p->first_name, $p->last_name, $p->email,
                $p->roles->pluck('name')->join(', '),
                self::STATUS_LABELS[$p->status],
                $p->is_volunteer ? 'Yes' : 'No',
                $p->last_login_at ? Carbon::parse($p->last_login_at)->format('Y-m-d H:i') : 'Never',
                $p->created_at?->format('Y-m-d H:i'),
                $p->created_by_name,
                $p->approved_at?->format('Y-m-d H:i'),
                $p->approved_by_name,
            ]);
        }

        $filename = 'users-'.now()->format('Y-m-d').'.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Create a new login-capable account. Two ways to get them in:
     * - no password: email them a "set your password" link (reuses
     *   Laravel's stock password-reset broker/route rather than a separate
     *   invite-token system);
     * - a password the admin chose: optionally make them pick their own on
     *   first login (EnsurePasswordChanged), and optionally email them
     *   where/how to log in — never the password itself.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES + [
            'password' => ['nullable', 'string', PasswordRule::defaults()],
            'require_password_change' => 'nullable|boolean',
            'send_login_email' => 'nullable|boolean',
        ]);
        $roleData = $data['people_roles'] ?? [];
        $permissionData = $data['person_permissions'] ?? [];
        $password = $data['password'] ?? null;

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
        // None of these are fillable — set only here.
        $person->forceFill([
            'created_by_person_id' => Auth::id(),
            'approved_by_person_id' => Auth::id(),
        ]);
        if ($password !== null) {
            $person->forceFill([
                'password' => Hash::make($password),
                'password_change_required' => (bool) ($data['require_password_change'] ?? true),
            ]);
        }
        $person->save();
        $this->permissionAssignment->syncRolesAndPermissions($person, $roleData, $permissionData);

        if ($password === null) {
            Password::sendResetLink(['email' => $person->email]);
            $message = 'User created. An email has been sent so they can set their password.';
        } elseif ($data['send_login_email'] ?? false) {
            $message = $this->sendLoginEmail($person)
                ? 'User created. Their login details (not the password) were emailed to them.'
                : 'User created, but the login email could not be sent — check Email Settings. Give them the login details yourself.';
        } else {
            $message = 'User created. Give them their password yourself — no email was sent.';
        }

        return response()->json([
            'message' => $message,
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

    public function deactivate($id)
    {
        return $this->runAction('deactivate', $id);
    }

    public function reactivate($id)
    {
        return $this->runAction('reactivate', $id);
    }

    public function approve($id)
    {
        return $this->runAction('approve', $id);
    }

    public function resendInvite($id)
    {
        return $this->runAction('resend_invite', $id);
    }

    /**
     * Set (or reset) an existing account's password to one the admin
     * chose — for someone whose email isn't arriving. Same options as
     * creating an account with a password.
     */
    public function setPassword(Request $request, $id)
    {
        $data = $request->validate([
            'password' => ['required', 'string', PasswordRule::defaults()],
            'require_password_change' => 'nullable|boolean',
            'send_login_email' => 'nullable|boolean',
        ]);
        $person = Person::whereNotNull('email')->findOrFail($id);

        try {
            $this->assertCanTakeOver($person);
        } catch (AccountActionRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $person->forceFill([
            'password' => Hash::make($data['password']),
            'password_change_required' => (bool) ($data['require_password_change'] ?? true),
        ])->save();

        $message = 'Password set. Give it to them yourself.';
        if ($data['send_login_email'] ?? false) {
            $message = $this->sendLoginEmail($person)
                ? 'Password set. Their login details (not the password) were emailed to them.'
                : 'Password set, but the login email could not be sent — check Email Settings.';
        }

        return response()->json(['message' => $message], 200);
    }

    /** Turn the "choose a new password at next login" requirement on or off. */
    public function requirePasswordChange(Request $request, $id)
    {
        $data = $request->validate(['required' => 'required|boolean']);

        return $this->runAction($data['required'] ? 'require_password_change' : 'clear_password_change', $id);
    }

    /**
     * Apply one account action to many accounts. Each is checked on its
     * own; ones that are refused are reported back and the rest still go
     * ahead.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(self::BULK_ACTIONS)],
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
        ]);

        $people = Person::whereNotNull('email')->whereIn('id', $data['ids'])->get();
        $done = 0;
        $skipped = [];
        foreach ($people as $person) {
            try {
                $this->applyAction($data['action'], $person);
                $done++;
            } catch (AccountActionRefused $e) {
                $skipped[] = ['id' => $person->id, 'name' => $person->full_name, 'reason' => $e->getMessage()];
            }
        }

        $message = $done.' of '.$people->count().' '.($people->count() === 1 ? 'account' : 'accounts').' updated.';
        if ($skipped) {
            $message .= ' '.count($skipped).' skipped.';
        }

        return response()->json(['message' => $message, 'done' => $done, 'skipped' => $skipped], 200);
    }

    private function runAction(string $action, $id): JsonResponse
    {
        $person = Person::whereNotNull('email')->findOrFail($id);

        try {
            return response()->json(['message' => $this->applyAction($action, $person)], 200);
        } catch (AccountActionRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /**
     * @throws AccountActionRefused
     */
    private function applyAction(string $action, Person $person): string
    {
        switch ($action) {
            case 'deactivate':
                // Block login without deleting the account or its history.
                if ($person->id === Auth::id()) {
                    throw new AccountActionRefused('You cannot deactivate your own account.', 422);
                }
                $this->assertHoldsEverything($person, 'deactivate');
                if (! $person->disabled_at) {
                    $person->update(['disabled_at' => now()]);
                }

                return 'User deactivated.';

            case 'reactivate':
                // Restore login access for a previously deactivated account.
                $person->update([
                    'disabled_at' => null,
                    // Admin override for a self-registered account still pending
                    // email verification (offline mode, or a broken mail provider
                    // self-verification can't route around) — same vouching as
                    // store() does for a brand-new admin-created account, so this
                    // person isn't immediately re-blocked by MustVerifyEmail.
                    'email_verified_at' => $person->email_verified_at ?? now(),
                ]);
                // An explicit admin action here is itself an approval — don't
                // leave a self-registered account stuck behind
                // EnsureAccountApproved after an admin has already vouched for
                // it via reactivate. Never overwrites an existing approval.
                $this->markApproved($person);

                return 'User reactivated.';

            case 'approve':
                // Approve a self-registered account that's been reviewed — clears
                // the pending-approval gate (EnsureAccountApproved) without
                // granting any roles/permissions on its own; pair with update()
                // to assign a role.
                // Mirrors reactivate's reasoning: an admin explicitly
                // approving this account is vouching for the address too, in
                // case mail never delivered.
                $person->update(['email_verified_at' => $person->email_verified_at ?? now()]);
                $this->markApproved($person);

                return 'User approved.';

            case 'resend_invite':
                // Resend the "set your password" email — for an account that
                // never completed setup, or whoever just forgot.
                if ($person->disabled_at) {
                    throw new AccountActionRefused('This account is deactivated.', 422);
                }
                $status = Password::sendResetLink(['email' => $person->email]);
                if ($status !== Password::RESET_LINK_SENT) {
                    throw new AccountActionRefused(__($status), 422);
                }

                return 'Invite email resent.';

            case 'require_password_change':
                $this->assertCanTakeOver($person);
                if (is_null($person->password)) {
                    throw new AccountActionRefused('This account has no password yet — they will choose one when they accept their invite.', 422);
                }
                $person->forceFill(['password_change_required' => true])->save();

                return 'They will be asked to choose a new password the next time they use the app.';

            case 'clear_password_change':
                $this->assertCanTakeOver($person);
                $person->forceFill(['password_change_required' => false])->save();

                return 'No longer required to change their password.';
        }

        throw new \InvalidArgumentException("Unknown account action {$action}");
    }

    private function markApproved(Person $person): void
    {
        if ($person->approved_at) {
            return;
        }
        $person->forceFill(['approved_at' => now(), 'approved_by_person_id' => Auth::id()])->save();
    }

    /**
     * Setting someone's password, or forcing them to change it, is close to
     * taking over the account — never for your own (use Profile), and only
     * for someone who holds nothing you don't.
     *
     * @throws AccountActionRefused
     */
    private function assertCanTakeOver(Person $person): void
    {
        if ($person->id === Auth::id()) {
            throw new AccountActionRefused('Change your own password from your Profile page.', 422);
        }
        $this->assertHoldsEverything($person, 'change the password of');
    }

    /**
     * @throws AccountActionRefused
     */
    private function assertHoldsEverything(Person $person, string $verb): void
    {
        $actingKeys = collect(Auth::user()->effectivePermissionKeys());
        $targetKeys = collect($person->effectivePermissionKeys());
        if ($targetKeys->diff($actingKeys)->isNotEmpty()) {
            throw new AccountActionRefused("You cannot {$verb} a person who holds a permission you do not have yourself.", 403);
        }
    }

    private function sendLoginEmail(Person $person): bool
    {
        try {
            Mail::to($person->email)->send(new AccountCreated($person, Person::find(Auth::id())));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send new-account email: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Every login-capable person, shaped for the page: permission overrides
     * flattened, plus status, last login and who created/approved them.
     */
    private function loadUsers(?array $ids = null)
    {
        $people = Person::whereNotNull('email')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->with(['people_roles', 'roles', 'person_permissions', 'createdBy', 'approvedBy'])
            ->withMax('loginHistory as last_login_at', 'logged_in_at')
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
            $person->status = self::status($person);
            // withMax hands back the raw column string; send an ISO time so
            // the browser reads it in the right timezone.
            $person->last_login_at = $person->last_login_at ? Carbon::parse($person->last_login_at)->toIso8601String() : null;
            $person->created_by_name = $person->createdBy?->full_name;
            $person->approved_by_name = $person->approvedBy?->full_name;
            $person->unsetRelation('createdBy');
            $person->unsetRelation('approvedBy');
        });

        return $people;
    }

    /** One of STATUS_LABELS' keys. */
    public static function status(Person $person): string
    {
        if ($person->disabled_at) {
            // Self-registration leaves an account disabled until email
            // verification (or this admin override) clears it — distinct from
            // an admin explicitly deactivating an already-verified account.
            return $person->email_verified_at ? 'deactivated' : 'unverified';
        }
        // approved_at is the separate gate EnsureAccountApproved enforces — a
        // self-registered account can be email-verified and still fully
        // blocked from the app until an admin approves it here.
        if (! $person->approved_at) {
            return 'pending_approval';
        }
        if (is_null($person->password)) {
            return 'invited';
        }

        return $person->password_change_required ? 'must_change_password' : 'active';
    }
}
