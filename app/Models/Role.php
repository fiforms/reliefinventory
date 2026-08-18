<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    protected $casts = [
        'visible_in_people_form' => 'boolean',
        'visible_in_user_admin' => 'boolean',
    ];

    /**
     * Define the many-to-many relationship with people.
     */
    public function people()
    {
        return $this->belongsToMany(Person::class, 'people_roles', 'role_id', 'person_id')
            ->withTimestamps();
    }

    /**
     * This role's default permission bundle.
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->withTimestamps();
    }
}
