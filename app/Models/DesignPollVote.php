<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One browser's ballot on a Design Votes poll (DesignVoteController): who voted, plus a like or dislike for each
 * option they reacted to (reactions). Voting again replaces the reactions.
 */
class DesignPollVote extends Model
{
    protected $fillable = ['poll_slug', 'voter_token_hash', 'person_id', 'voter_name', 'ip_address'];

    protected $hidden = ['voter_token_hash', 'ip_address'];

    public function reactions(): HasMany
    {
        return $this->hasMany(DesignPollReaction::class);
    }
}
