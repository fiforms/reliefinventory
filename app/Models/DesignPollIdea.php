<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A free-form "Have another idea?" note sent from a Design Votes page (DesignVoteController::storeIdea). */
class DesignPollIdea extends Model
{
    protected $fillable = ['poll_slug', 'idea', 'person_id', 'voter_name', 'email', 'ok_to_contact', 'ip_address'];

    protected $casts = ['ok_to_contact' => 'boolean'];

    protected $hidden = ['ip_address'];
}
