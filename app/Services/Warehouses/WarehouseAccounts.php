<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\PeopleRoles;
use App\Models\User;
use App\Services\FirstRunService;
use App\Services\InvitationRefused;
use App\Services\MembershipInvitations;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The current warehouse's logins in a backup (`database/accounts.json`) and back again after a restore
 * (MULTI_WAREHOUSE.md §6, "Backups"). The accounts schema isn't in a warehouse backup, so without this a
 * backup restored onto a new instance would have no working logins.
 *
 * An instance-level restore (the first-run wizard, the CLI) attaches memberships directly; an untrusted one (`$untrusted`
 * in relinkAfterRestore) turns an existing login into an invitation and never creates an instance admin. Which restores are
 * untrusted is decided by their caller (the setup-code restore, phase 3; a warehouse admin's, phase 4).
 */
class WarehouseAccounts
{
    public const FORMAT = 1;

    /** Set by an untrusted restore around its migration step, so the old `move_logins_to_accounts` migration attaches no one. */
    public static bool $untrustedRestore = false;

    /** What that migration found in an old dump's people table instead: the entries relinkAfterRestore() uses when the backup has no accounts.json. */
    public static ?array $legacyLogins = null;

    public function __construct(private WarehouseContext $context) {}

    /**
     * Who logs in to this warehouse. The password hash goes only with an account whose sole home is here, so one
     * warehouse's backups never carry the credentials of people who also belong elsewhere. Never the
     * instance-admin flag.
     */
    public function export(): array
    {
        $warehouse = $this->context->current();
        $memberships = Membership::with('account')->where('warehouse_id', $warehouse->id)->get();

        return [
            'format' => self::FORMAT,
            'warehouse' => ['id' => $warehouse->id, 'uuid' => $warehouse->uuid],
            'accounts' => $memberships->filter(fn (Membership $m) => $m->account)->map(fn (Membership $m) => [
                'person_id' => $m->person_id,
                'email' => $m->account->email,
                'email_verified_at' => $m->account->email_verified_at?->toIso8601String(),
                'password' => $m->account->isSoleHome($warehouse->id) ? $m->account->password : null,
            ])->values()->all(),
        ];
    }

    /** Before a restored dump is migrated: its people are not the ones these memberships point at. */
    public function forgetMemberships(): void
    {
        Membership::where('warehouse_id', $this->context->current()->id)->delete();
    }

    /**
     * After a restore: rebuild this warehouse's memberships and people.account_id. From accounts.json when the
     * backup has one; otherwise (an older backup, already migrated into accounts) by each login person's email,
     * which people.email mirrors. An account that already exists here is kept exactly as it is
     * (keep-what-is-there); a missing one is created. Returns one line per login, for the restore report.
     *
     * $untrusted is a restore by someone who is not the instance's operator (a setup-code holder, later a warehouse admin): the
     * backup is theirs to write, so it must not touch anyone else's login (MULTI_WAREHOUSE.md §6). An email that already has a
     * login becomes an **invitation** (the owner accepts it; nobody is emailed because a backup said so) instead of a
     * membership; a login it creates is never marked verified; and nobody is made an instance admin.
     *
     * @return list<string>
     */
    public function relinkAfterRestore(?array $export, bool $untrusted = false): array
    {
        $warehouse = $this->context->current();
        $this->forgetMemberships();

        if ($export === null && self::$legacyLogins !== null) {
            $export = ['accounts' => self::$legacyLogins];
        }
        self::$legacyLogins = null;
        $entries = $export !== null
            ? collect($export['accounts'] ?? [])->keyBy('person_id')
            : DB::table('people')->whereNotNull('account_id')->get(['id', 'email'])
                ->mapWithKeys(fn ($p) => [$p->id => ['person_id' => $p->id, 'email' => $p->email]]);
        // The restored rows' account ids are another instance's (or another time's): meaningless here, and as a
        // unique column they would collide with the ids handed out below.
        DB::table('people')->whereNotNull('account_id')->update(['account_id' => null]);

        $report = [];
        foreach ($entries as $personId => $entry) {
            $email = trim((string) ($entry['email'] ?? ''));
            if ($email === '' || ! DB::table('people')->where('id', $personId)->exists()) {
                continue;
            }

            $account = Account::findByEmail($email);
            if ($account && $untrusted) {
                try {
                    app(MembershipInvitations::class)->invite($warehouse, (int) $personId, $email, 'restore');
                    $report[] = "{$email}: already has a login, so it was invited (it joins only if its owner accepts)";
                } catch (InvitationRefused $e) {
                    $report[] = "{$email}: {$e->getMessage()}";
                }

                continue;
            }
            if ($account) {
                $report[] = "{$email}: existing login kept";
            } else {
                $account = new Account(['email' => $email]);
                $account->forceFill([
                    'password' => $entry['password'] ?? null,
                    'email_verified_at' => $untrusted ? null : ($entry['email_verified_at'] ?? null),
                ])->save();
                $report[] = "{$email}: login created".($account->password ? '' : ' (no password: they set one by email)');
            }

            Membership::create([
                'account_id' => $account->id, 'warehouse_id' => $warehouse->id, 'person_id' => $personId,
                'status' => Membership::ACTIVE, 'is_training' => $warehouse->is_training,
            ]);
            DB::table('people')->where('id', $personId)->update(['account_id' => $account->id, 'email' => $account->email]);
        }

        // Sweeping up logins that belong nowhere is for an instance-level restore. An untrusted one must not delete anyone's login: with
        // several warehouses an account with no membership may be someone about to join (the code holder's own, made a moment ago).
        if (! $untrusted) {
            $this->removeOrphans();
            $this->ensureInstanceAdmin();
        }

        return $report;
    }

    /**
     * The person in a restored backup who is the login's owner (same email, no login yet): linked to the login and made Administrator,
     * so restoring your own warehouse's backup gives you your old person back. Any invitation the restore made for that person is
     * closed. Null when the backup has no such person.
     */
    public function adoptRestoredPerson(Account $account): ?User
    {
        $warehouse = $this->context->current();
        $row = DB::table('people')->where('email', $account->email)->first(['id', 'account_id', 'is_trainee']);
        if (! $row) {
            return null;
        }
        if ($row->account_id !== null || $row->is_trainee) {
            throw new RuntimeException('The backup has a person with your email that cannot be linked to your login. Ask for a new setup code and use "Set up a fresh warehouse", then import people another way.');
        }

        DB::table('people')->where('id', $row->id)->update(['account_id' => $account->id]);
        Membership::create([
            'account_id' => $account->id, 'warehouse_id' => $warehouse->id, 'person_id' => $row->id,
            'status' => Membership::ACTIVE, 'is_training' => $warehouse->is_training,
        ]);
        PeopleRoles::firstOrCreate(['person_id' => $row->id, 'role_id' => FirstRunService::ADMINISTRATOR_ROLE_ID]);
        MembershipInvitation::where('warehouse_id', $warehouse->id)->where('person_id', $row->id)->update(['status' => 'accepted', 'responded_at' => now(), 'token_hash' => null]);

        return User::findOrFail($row->id);
    }

    /**
     * The admin who ran an untrusted restore of their own warehouse stays its Administrator: their person from the backup if it has
     * one with their email, otherwise a new one. Without this the restore could lock them out (their login becomes an invitation like
     * everyone else's, or isn't in the backup at all). They proved who they are with their password when they started it.
     */
    public function attachRestorer(Account $account, string $firstName, string $lastName): User
    {
        if ($user = $this->adoptRestoredPerson($account)) {
            return $user;
        }
        $warehouse = $this->context->current();
        $user = new User;
        $user->forceFill([
            'first_name' => $firstName, 'last_name' => $lastName, 'email' => $account->email,
            'account_id' => $account->id, 'approved_at' => now(),
        ])->save();
        Membership::create([
            'account_id' => $account->id, 'warehouse_id' => $warehouse->id, 'person_id' => $user->id,
            'status' => Membership::ACTIVE, 'is_training' => $warehouse->is_training,
        ]);
        PeopleRoles::create(['person_id' => $user->id, 'role_id' => FirstRunService::ADMINISTRATOR_ROLE_ID]);

        return $user;
    }

    /**
     * After this warehouse is erased (setup:reset): no logins here, and none left that belong nowhere — not even
     * an instance admin, or the setup wizard that follows couldn't create the administrator again.
     * TODO (phase 4): with several warehouses, an erased warehouse must not remove an instance admin who still
     * runs the instance.
     */
    public function forgetAll(): void
    {
        $this->forgetMemberships();
        Account::whereDoesntHave('memberships')->delete();
    }

    /** Logins left with no warehouse at all (they belonged only to the data just replaced). */
    private function removeOrphans(): void
    {
        Account::where('is_instance_admin', false)->whereDoesntHave('memberships')->delete();
    }

    /**
     * A restore onto a brand-new instance leaves nobody able to run it; the restored Administrators become
     * instance admins, the same rule the accounts migration used. Never when the instance already has one.
     */
    private function ensureInstanceAdmin(): void
    {
        if (Account::where('is_instance_admin', true)->exists()) {
            return;
        }

        $admins = DB::table('people_roles')->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->pluck('person_id');
        $accountIds = DB::table('people')->whereIn('id', $admins)->whereNotNull('account_id')->pluck('account_id');
        Account::whereIn('id', $accountIds)->update(['is_instance_admin' => true]);
    }
}
