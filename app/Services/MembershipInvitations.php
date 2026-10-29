<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Mail\MembershipInvitationMail;
use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Attaching an existing login to a person in a warehouse is an offer its owner accepts, never something a warehouse does to it
 * (MULTI_WAREHOUSE.md §6): otherwise any warehouse admin could attach anyone's account, an instance admin's included, and change
 * the victim's sole-membership status. Raised by an admin adding someone whose email already has a login, by an untrusted
 * restore's accounts.json (WarehouseAccounts), and later by importers. Accepting needs the account's own password.
 *
 * An invitation is for one person row in one warehouse. It can carry an emailed link (an admin's invitation) or not
 * (a restore's: nobody is emailed because a backup said so); either way it is also listed in the app to the invited login.
 */
class MembershipInvitations
{
    public function __construct(private WarehouseContext $context) {}

    /**
     * Offer $email's login the person $personId in $warehouse. Offering again to the same person renews it (new link, new
     * expiry) and replaces whatever became of the earlier one.
     *
     * @return array{invitation: MembershipInvitation, mailed: bool|null} mailed is null when no email was asked for
     *
     * @throws InvitationRefused
     */
    public function invite(Warehouse $warehouse, int $personId, string $email, string $source, ?string $invitedBy = null, bool $sendEmail = false): array
    {
        $email = trim($email);
        $account = Account::findByEmail($email);
        if ($account && Membership::where('account_id', $account->id)->where('warehouse_id', $warehouse->id)->exists()) {
            throw new InvitationRefused('That login already belongs to this warehouse.');
        }

        $token = $sendEmail ? Str::random(40) : null;
        $invitation = MembershipInvitation::updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'person_id' => $personId],
            [
                'email' => $account->email ?? $email,
                'token_hash' => $token ? MembershipInvitation::hashToken($token) : null,
                'status' => MembershipInvitation::PENDING,
                'source' => $source,
                'invited_by' => $invitedBy,
                'expires_at' => now()->addDays((int) config('warehouses.invitation_days')),
                'responded_at' => null,
            ],
        );

        return ['invitation' => $invitation, 'mailed' => $token ? $this->email($warehouse, $invitation, $token) : null];
    }

    /** The accept link in an email: the warehouse's own public address, so it works from a phone's mail app. */
    public function url(Warehouse $warehouse, string $token): string
    {
        return $warehouse->publicUrl('/invitations/'.$token);
    }

    private function email(Warehouse $warehouse, MembershipInvitation $invitation, string $token): bool
    {
        try {
            Mail::to($invitation->email)->send(new MembershipInvitationMail($warehouse, $invitation->email, $this->url($warehouse, $token), $invitation->invited_by, $invitation->expires_at));

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to send an invitation email: '.$e->getMessage());

            return false;
        }
    }

    /** The open invitation behind an emailed link, or null (unknown, used, withdrawn or expired alike). */
    public function findByToken(string $token): ?MembershipInvitation
    {
        return MembershipInvitation::open()->where('token_hash', MembershipInvitation::hashToken($token))->first();
    }

    /**
     * What a login has been invited to: open invitations to its email, for warehouses that are up, each with the warehouse's
     * name and the person it would become there.
     *
     * @return list<array{id:int,warehouse:string,person:string,invited_by:?string,source:string,expires_at:string}>
     */
    public function listFor(Account $account): array
    {
        $invitations = MembershipInvitation::open()->where('email', $account->email)->orderBy('id')->get();
        $warehouses = Warehouse::whereIn('id', $invitations->pluck('warehouse_id'))->where('status', 'active')->get()->keyBy('id');

        return $invitations->filter(fn ($i) => $warehouses->has($i->warehouse_id))->map(fn ($i) => [
            'id' => $i->id,
            'warehouse' => $warehouses[$i->warehouse_id]->display_name,
            'person' => $this->context->run($warehouses[$i->warehouse_id], fn () => $this->personName($i->person_id)),
            'invited_by' => $i->invited_by,
            'source' => $i->source,
            'expires_at' => $i->expires_at->toIso8601String(),
        ])->values()->all();
    }

    /** Open invitations raised in $warehouse, for its admins' list. */
    public function openIn(Warehouse $warehouse)
    {
        return MembershipInvitation::open()->where('warehouse_id', $warehouse->id)->orderBy('id')->get();
    }

    /**
     * The login joins: the person in the warehouse is linked to it and the membership made. $account must be the one invited
     * and already proven by the caller (password). All or nothing across the two schemas.
     *
     * @throws InvitationRefused
     */
    public function accept(MembershipInvitation $invitation, Account $account): Membership
    {
        return $invitation->getConnection()->transaction(function () use ($invitation, $account) {
            $locked = MembershipInvitation::open()->whereKey($invitation->id)->lockForUpdate()->first();
            if (! $locked || strcasecmp($locked->email, $account->email) !== 0) {
                throw new InvitationRefused('This invitation is no longer available.');
            }
            $warehouse = Warehouse::find($locked->warehouse_id);
            if (! $warehouse?->isActive()) {
                throw new InvitationRefused('That warehouse is not available right now.');
            }
            if (Membership::where('account_id', $account->id)->where('warehouse_id', $warehouse->id)->exists()) {
                throw new InvitationRefused('You already belong to that warehouse.');
            }

            $this->context->run($warehouse, function () use ($locked, $account) {
                // Only a person who has no login yet and isn't a trainee: the row may have changed since it was offered.
                $linked = DB::table('people')->where('id', $locked->person_id)->whereNull('account_id')->where('is_trainee', false)
                    ->update(['account_id' => $account->id, 'email' => $account->email]);
                if ($linked !== 1) {
                    throw new InvitationRefused('That invitation no longer matches a person in the warehouse. Ask them to invite you again.');
                }
            });

            try {
                $membership = Membership::create([
                    'account_id' => $account->id, 'warehouse_id' => $warehouse->id, 'person_id' => $locked->person_id,
                    'status' => Membership::ACTIVE, 'is_training' => $warehouse->is_training,
                ]);
            } catch (Throwable $e) {
                $this->context->run($warehouse, fn () => DB::table('people')->where('id', $locked->person_id)->where('account_id', $account->id)->update(['account_id' => null]));
                throw $e;
            }

            $locked->forceFill(['status' => 'accepted', 'responded_at' => now(), 'token_hash' => null])->save();

            return $membership;
        });
    }

    public function decline(MembershipInvitation $invitation, Account $account): void
    {
        if (strcasecmp($invitation->email, $account->email) === 0 && $invitation->isOpen()) {
            $invitation->forceFill(['status' => 'declined', 'responded_at' => now(), 'token_hash' => null])->save();
        }
    }

    /** The warehouse takes its offer back. */
    public function withdraw(MembershipInvitation $invitation): void
    {
        if ($invitation->isOpen()) {
            $invitation->forceFill(['status' => 'withdrawn', 'responded_at' => now(), 'token_hash' => null])->save();
        }
    }

    private function personName(int $personId): string
    {
        $person = DB::table('people')->where('id', $personId)->first(['first_name', 'last_name', 'organization']);
        $name = trim(($person->first_name ?? '').' '.($person->last_name ?? ''));

        return $name !== '' ? $name : ($person->organization ?? 'a person');
    }
}
