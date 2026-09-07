<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1), same shape as PinLoginSetting/BannerSetting —
 * admin-editable replacement for the FEEDBACK_INSTANCE_ROLE /
 * FEEDBACK_INSTANCE_IDENTIFIER / FEEDBACK_CENTRAL_INGEST_URL /
 * FEEDBACK_SHARED_SECRET env vars. `shared_secret` uses Eloquent's
 * `encrypted` cast (app key-derived, not plaintext at rest) and is
 * transparently decrypted on read/encrypted on write by the cast itself.
 */
class FeedbackAggregationSetting extends Model
{
    protected $table = 'feedback_aggregation_settings';

    protected $fillable = [
        'instance_role',
        'instance_identifier',
        'central_ingest_url',
        'shared_secret',
    ];

    protected $casts = [
        'shared_secret' => 'encrypted',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /**
     * Whether central push is configured at all — mirrors the old
     * config()-based check in FeedbackPushService.
     */
    public function pushConfigured(): bool
    {
        return $this->instance_role === 'remote'
            && filled($this->central_ingest_url)
            && filled($this->shared_secret);
    }

    /**
     * Defaults to this instance's own APP_URL host when left blank — see
     * FeedbackPushService::instanceIdentifier(), which delegates here.
     */
    public function resolvedIdentifier(): string
    {
        if (filled($this->instance_identifier)) {
            return $this->instance_identifier;
        }

        return parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url');
    }
}
