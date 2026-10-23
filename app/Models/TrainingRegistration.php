<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One trainee self sign-up (TraineeSignupController) — the follow-up answers beside the trainee Person. */
class TrainingRegistration extends Model
{
    protected $fillable = [
        'person_id', 'email', 'phone', 'wants_updates', 'help_volunteer', 'help_software', 'help_organization',
        'help_other', 'recommend', 'comments', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'wants_updates' => 'boolean',
        'help_volunteer' => 'boolean',
        'help_software' => 'boolean',
        'help_organization' => 'boolean',
        'help_other' => 'boolean',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}
