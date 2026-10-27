<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1), same shape as PinLoginSetting/BannerSetting — admin-editable settings for centralized
 * feedback aggregation: whether this instance is a `remote` (sends its reports to a central instance) or the
 * `central` one, and, for a remote, which paired instance (`feedback_trust_id`, an approved `instance` Trust) it
 * sends them through. There is no address or secret here any more: the trust carries the peer's address and key
 * (see App\Services\Federation).
 */
class FeedbackAggregationSetting extends Model
{
    protected $table = 'feedback_aggregation_settings';

    protected $fillable = [
        'instance_role',
        'feedback_trust_id',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /** Whether central push is configured at all: a remote whose chosen trust is still approved. */
    public function pushConfigured(): bool
    {
        return $this->instance_role === 'remote' && $this->feedbackTrust?->isApproved() === true;
    }

    /** The instance trust reports are sent through. */
    public function feedbackTrust()
    {
        return $this->belongsTo(Trust::class, 'feedback_trust_id');
    }
}
