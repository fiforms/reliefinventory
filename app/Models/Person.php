<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Models\Concerns\HasPermissions;
use App\Models\Concerns\HasPinLogin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    use HasFactory, HasPermissions, HasPinLogin;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'people';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'organization',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'zip',
        'county_id',
        'comments',
        // Admin-assigned (the physical badge is issued by staff), unlike
        // pin_hash below which is deliberately NOT fillable — a PIN is
        // self-service and must only ever be written by PinController,
        // never by a raw mass-assignment payload through PeopleController.
        'badge_code',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Person had no $hidden at all until this was found (2026-08-16) while
     * checking whether the new pin_hash column would leak the same way —
     * it would have, and password already was: PeopleController::index()
     * serializes full Person models with no column restriction, so every
     * bcrypt password hash was reaching the browser for anyone holding
     * manage-people (the whole volunteer tier, by default). Matches what
     * User::$hidden already correctly did for the same table.
     */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    /**
     * Combined display name for search/combo controls — there is no single
     * name column, and controls like ComboBox can only display one field.
     */
    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        // Organization-only records (no personal name) still need a visible label
        return $name !== '' ? $name : ($this->organization ?? '');
    }

    /**
     * system_key marks a record as system-provided (e.g. the canonical
     * "Unknown Donor" placeholder) — deliberately not in $fillable, so it
     * can only ever be set directly by a migration, never through the
     * People form/API.
     */
    public function isSystem(): bool
    {
        return ! is_null($this->system_key);
    }

    /**
     * Define relationships to other models (if applicable).
     */

    // Example relationship with OrderDonation (assuming a person can have many orders or donations)
    public function orderDonations()
    {
        return $this->hasMany(OrderDonation::class, 'person_id');
    }

    public function county()
    {
        return $this->belongsTo(County::class, 'county_id');
    }

    /**
     * Assign a role to a person.
     *
     * @param  mixed  $role
     */
    public function assignRole($role)
    {
        if (is_numeric($role)) {
            $this->roles()->attach($role);
        } elseif ($role instanceof Role) {
            $this->roles()->attach($role->id);
        } elseif (is_string($role)) {
            $role = Role::where('name', $role)->first();
            if ($role) {
                $this->roles()->attach($role->id);
            }
        }
    }

    /**
     * Remove a role from a person.
     *
     * @param  mixed  $role
     */
    public function removeRole($role)
    {
        if (is_numeric($role)) {
            $this->roles()->detach($role);
        } elseif ($role instanceof Role) {
            $this->roles()->detach($role->id);
        } elseif (is_string($role)) {
            $role = Role::where('name', $role)->first();
            if ($role) {
                $this->roles()->detach($role->id);
            }
        }
    }

    /**
     * Check if the person has a specific role.
     *
     * @param  string  $roleName
     * @return bool
     */
    public function hasRole($roleName)
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Sync roles (remove old and add new roles).
     */
    public function syncRoles(array $roles)
    {
        $roleIds = Role::whereIn('name', $roles)->pluck('id')->toArray();
        $this->roles()->sync($roleIds);
    }
}
