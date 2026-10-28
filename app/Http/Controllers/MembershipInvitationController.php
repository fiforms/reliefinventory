<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Accounts\Account;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\Warehouse;
use App\Models\User;
use App\Services\InvitationRefused;
use App\Services\MembershipInvitations;
use App\Services\PinLoginService;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting an invitation to join a warehouse (MULTI_WAREHOUSE.md §6). Two ways in, one rule: the account's own password.
 *  - the emailed link, `/invitations/{token}` on the inviting warehouse's address (public: the invitee has no membership
 *    there yet, so can't be signed in to it); accepting signs them in to it;
 *  - `/account/invitations`, for a login signed in to any warehouse it already belongs to: every invitation to its email,
 *    including ones that were never emailed (a restore's).
 * Wrong passwords are counted per invitation / per account, so an emailed link can't be used to guess a password.
 */
class MembershipInvitationController extends Controller
{
    public function __construct(private MembershipInvitations $invitations, private WarehouseContext $context) {}

    public function show(string $token): Response
    {
        $invitation = $this->forThisWarehouse($token);

        return Inertia::render('Auth/AcceptInvitation', [
            'warehouseName' => $this->context->current()->display_name,
            'email' => $invitation->email,
            'invitedBy' => $invitation->invited_by,
            'token' => $token,
        ]);
    }

    public function acceptLink(Request $request, string $token, PinLoginService $pinLogin): RedirectResponse
    {
        $invitation = $this->forThisWarehouse($token);
        $request->validate(['password' => ['required', 'string']]);

        $account = $this->proved($invitation->email, (string) $request->input('password'), "invitation:{$invitation->id}");

        try {
            $membership = $this->invitations->accept($invitation, $account);
        } catch (InvitationRefused $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        Auth::login(User::findOrFail($membership->person_id));
        $request->session()->regenerate();
        $pinLogin->grantTrust($pinLogin->currentDevice($request), $membership->person_id);

        return redirect()->route('dashboard')->with('status', 'Welcome to '.$this->context->current()->display_name.'.');
    }

    public function index(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('Account/Invitations', [
            'invitations' => $this->invitations->listFor($account),
            'joined' => session('joined'),
            'breadcrumb' => [['href' => '/account/invitations', 'title' => 'Invitations']],
        ]);
    }

    public function accept(Request $request, int $id): RedirectResponse
    {
        $account = $this->account($request);
        $invitation = $this->mine($account, $id);
        $request->validate(['password' => ['required', 'string']]);
        $this->proved($account->email, (string) $request->input('password'), "invitation-account:{$account->id}");

        try {
            $this->invitations->accept($invitation, $account);
        } catch (InvitationRefused $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        $warehouse = Warehouse::findOrFail($invitation->warehouse_id);

        return redirect()->route('account.invitations')->with('joined', ['name' => $warehouse->display_name, 'url' => $warehouse->publicUrl('/')]);
    }

    public function decline(Request $request, int $id): RedirectResponse
    {
        $account = $this->account($request);
        $this->invitations->decline($this->mine($account, $id), $account);

        return redirect()->route('account.invitations');
    }

    /** The invitation behind a link, if it is open and belongs to the warehouse this request reached; otherwise a plain 404. */
    private function forThisWarehouse(string $token): MembershipInvitation
    {
        $invitation = $this->invitations->findByToken($token);
        abort_unless($invitation && $invitation->warehouse_id === $this->context->current()->id, 404);

        return $invitation;
    }

    private function account(Request $request): Account
    {
        return $request->user()->account ?? abort(403, 'This login has no account to invite.');
    }

    private function mine(Account $account, int $id): MembershipInvitation
    {
        return MembershipInvitation::open()->whereKey($id)->where('email', $account->email)->first() ?? abort(404);
    }

    /** The account for this email and password, counting wrong tries under $key (5 per 15 minutes). */
    private function proved(string $email, string $password, string $key): Account
    {
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many wrong tries. Wait a few minutes and try again.']);
        }
        $account = Account::verified($email, $password);
        if (! $account) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        RateLimiter::clear($key);

        return $account;
    }
}
