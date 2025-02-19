<?php 
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeopleRoles extends Model
{
    use HasFactory;
    
    protected $table = 'people_roles';
    
    protected $fillable = ['person_id','role_id'];


}
