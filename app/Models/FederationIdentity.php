<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An instance's (later: a tenant's) Ed25519 identity. `public_key` is base64url of the 32-byte key and is
 * shared freely; `private_key` (base64url of the 64-byte libsodium secret key) is `encrypted` with APP_KEY
 * and never serialized. A null private_key means the key could not be decrypted after a restore onto a
 * different APP_KEY — the identity is lost and must be regenerated (peers then have to re-approve it).
 */
class FederationIdentity extends Model
{
    protected $table = 'federation_identities';

    protected $fillable = ['uuid', 'name', 'base_url', 'public_key', 'private_key'];

    protected $hidden = ['private_key'];

    protected $casts = [
        'private_key' => 'encrypted',
    ];
}
