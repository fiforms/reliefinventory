<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One browser's vote on a Design Votes poll (DesignVoteController). Voting again replaces it. */
class DesignPollVote extends Model
{
    protected $fillable = [
        'poll_slug', 'option_key', 'voter_token_hash', 'person_id', 'voter_name', 'voter_role', 'comment', 'ip_address',
    ];

    protected $hidden = ['voter_token_hash', 'ip_address'];
}
