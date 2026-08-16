<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Hash;

/**
 * Shared between Person and User (same table/row — see HasPermissions'
 * doc comment for why) so the shared-terminal PIN-unlock logic works
 * identically regardless of which model a given controller happens to be
 * holding. pin_hash is deliberately not in either model's $fillable — it
 * must only ever be written by PinController, never a mass-assignment
 * payload.
 */
trait HasPinLogin
{
    public function hasPin(): bool
    {
        return ! is_null($this->pin_hash);
    }

    public function verifyPin(string $pin): bool
    {
        return $this->pin_hash && Hash::check($pin, $this->pin_hash);
    }
}
