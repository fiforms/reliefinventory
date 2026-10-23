<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Mail;

use App\Models\Person;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent (optionally) when an administrator creates an account with a
 * password they chose — where and how to log in, never the password
 * itself, which the administrator hands over separately.
 */
class AccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Person $person, public ?Person $createdBy = null) {}

    public function build()
    {
        return $this->subject('Your '.config('app.name').' account')
            ->view('emails.account-created');
    }
}
