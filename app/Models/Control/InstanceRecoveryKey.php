<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id = 1) in the control schema: the instance's recovery PUBLIC key, which whole-instance backups seal every warehouse key to.
 * Only the public half exists here; written only by App\Services\InstanceRecoveryKeys.
 */
class InstanceRecoveryKey extends Model
{
    protected $connection = 'control';

    protected $table = 'instance_recovery_key';

    // One row, id 1, not auto-incremented.
    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = ['acknowledged_at' => 'datetime'];
}
