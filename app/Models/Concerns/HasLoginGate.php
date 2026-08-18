<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Concerns;

/**
 * Shared between Person and User (same table/row — see HasPermissions'
 * doc comment for why) so admin-initiated deactivation blocks both real
 * login paths identically. There are two independent places a session
 * actually gets created — a plain email+password login
 * (AuthenticatedSessionController) and PIN unlock (UnlockController) —
 * both must check this, since PIN unlock never goes through the former.
 */
trait HasLoginGate
{
    public function isLoginDisabled(): bool
    {
        return ! is_null($this->disabled_at);
    }
}
