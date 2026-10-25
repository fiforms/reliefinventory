<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A voter's like or dislike of one Design Votes option, with an optional "why" (DesignVoteController::store). */
class DesignPollReaction extends Model
{
    public const REACTIONS = ['like', 'dislike'];

    protected $fillable = ['design_poll_vote_id', 'option_key', 'reaction', 'comment'];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(DesignPollVote::class, 'design_poll_vote_id');
    }
}
