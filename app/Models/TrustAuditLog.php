<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only record of trust events: enrolled, grants changed, revoked, key changed, identity regenerated. */
class TrustAuditLog extends Model
{
    protected $table = 'trust_audit_log';

    public $timestamps = false;

    protected $fillable = ['trust_id', 'event', 'actor_person_id', 'detail'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];
}
