<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Models\Concerns\HasLoginGate;
use App\Models\Concerns\HasPermissions;
use App\Models\Concerns\HasPinLogin;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasLoginGate, HasPermissions, HasPinLogin, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $table = 'people';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'organization',
        'address',
        'city',
        'state',
        'zip',
        'county_id',
        'requested_track',
        // Set server-side by RegisteredUserController (self-registration
        // starts inactive), cleared by VerifyEmailController on successful
        // verification or by UserAdminController::reactivate as an admin
        // override — never accepted from request input.
        'disabled_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',        ];
    }

    /**
     * The "booted" method of the model.
     * This applies a global scope to filter out users where email is NULL.
     */
    protected static function booted()
    {
        static::addGlobalScope('excludeNullPasswords', function (Builder $builder) {
            $builder->whereNotNull('email');
        });
    }
}
