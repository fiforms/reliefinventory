<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to the provisioner (MULTI_WAREHOUSE.md §7): do one of COMMANDS to one warehouse. A request only; the
 * provisioner decides from the warehouse's own row whether it is allowed.
 */
class ProvisioningCommand extends Model
{
    protected $connection = 'control';

    /** `create`: schema, user and password for a new warehouse. `adopt`: the same for the default warehouse, after renaming its schema. `drop`: schema and user of an archived (or never-finished) warehouse. `instance-backup`: the whole-instance backup an upgrade takes before migrating (no warehouse, no parameters: it only ever writes the fixed `premigrate` zip, without keys). */
    public const COMMANDS = ['create', 'adopt', 'drop', 'instance-backup'];

    protected $fillable = ['warehouse_id', 'command', 'requested_by'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }
}
