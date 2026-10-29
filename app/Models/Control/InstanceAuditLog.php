<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * What an instance admin did to the instance itself (MULTI_WAREHOUSE.md §6): creating, archiving and removing warehouses, mapping
 * hostnames, switching a training warehouse. Append-only, in the control schema, and it names the warehouse by id and by the name it
 * had then, so an entry still reads after the warehouse is gone. A warehouse's own audit trails (trust_audit_log and the like) live
 * in its schema and go with it.
 */
class InstanceAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'control';

    protected $table = 'instance_audit_log';

    protected $fillable = ['actor', 'action', 'warehouse_id', 'subject', 'details'];

    protected $casts = ['details' => 'array'];

    /** Never throws: a failed log line must not undo or block what was done. */
    public static function record(string $action, ?Warehouse $warehouse = null, array $details = [], ?string $actor = null): void
    {
        try {
            self::create([
                'actor' => $actor ?? self::actor(),
                'action' => $action,
                'warehouse_id' => $warehouse?->id,
                'subject' => $warehouse?->display_name,
                'details' => $details ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Who is acting: the signed-in person (the instance console, later), else the command line. */
    public static function actor(): string
    {
        return Auth::user()?->email ?? 'command line';
    }
}
