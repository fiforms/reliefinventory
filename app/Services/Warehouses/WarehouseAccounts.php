<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Services\FirstRunService;
use App\Services\InvitationRefused;
use App\Services\MembershipInvitations;
use Illuminate\Support\Facades\DB;

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

        $this->removeOrphans();
        if (! $untrusted) {
            $this->ensureInstanceAdmin();
        }

        return $report;
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
