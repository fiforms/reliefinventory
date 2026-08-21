<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A permanent record of successful logins (email/password and PIN unlock),
 * recorded alongside the session/trust bookkeeping each auth path already
 * does — see AuthenticatedSessionController::store and
 * UnlockController::attemptPin. Backs the login-history section of the
 * "Who's Logged In" admin view (ActiveSessionController).
 */
class LoginHistory extends Model
{
    protected $table = 'login_history';

    protected $fillable = [
        'person_id',
        'method',
        'ip_address',
        'user_agent',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public static function record(int $personId, string $method, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        static::create([
            'person_id' => $personId,
            'method' => $method,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'logged_in_at' => now(),
        ]);
    }
}
