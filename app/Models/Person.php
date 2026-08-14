<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Models\Concerns\HasPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    use HasFactory, HasPermissions;

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
        'comments',
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
