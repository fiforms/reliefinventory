<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One permission on a trust. direction: 'inbound' = the peer may do it to us; 'outbound' = we may do it to the peer. */
class TrustGrant extends Model
{
    protected $fillable = ['trust_id', 'permission', 'direction'];
}
