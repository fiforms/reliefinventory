<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1), same shape as PinLoginSetting — see the
 * 2026_08_17 migration for why `version` exists (invalidating per-user
 * dismissals whenever the active banner's content changes).
 */
class BannerSetting extends Model
{
    protected $table = 'banner_settings';

    protected $fillable = [
        'type',
        'message',
        'version',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /**
     * Update type/message, bumping version only when the content actually
     * changed — so a no-op save doesn't needlessly reset every dismissal.
     */
    public function applyChange(?string $type, ?string $message): void
    {
        $changed = $this->type !== $type || $this->message !== $message;

        $this->type = $type;
        $this->message = $message;

        if ($changed) {
            $this->version += 1;
        }

        $this->save();
    }
}
