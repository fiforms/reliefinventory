<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Mail;

use App\Models\Control\Warehouse;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/** Tells an existing login it has been invited to a warehouse, with the link that accepts (MembershipInvitations). */
class MembershipInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public Warehouse $warehouse, public string $email, public string $url, public ?string $invitedBy, public Carbon $expiresAt) {}

    public function build()
    {
        return $this->subject("You're invited to {$this->warehouse->display_name}")->view('emails.membership-invitation');
    }
}
