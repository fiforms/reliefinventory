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

    /**
     * True for a self-registered account nobody with authority has vetted
     * yet — distinct from isLoginDisabled() (which only blocks *starting* a
     * new session) and from email verification (which only proves the
     * address is real). EnsureAccountApproved uses this to keep an
     * already-authenticated pending session confined to the registration
     * track/pending pages instead of the app itself. Every pre-existing
     * account was backfilled to approved on migration, and every
     * admin-created account is stamped approved at creation, so this is
     * only ever true for someone who registered themselves and hasn't been
     * reviewed yet.
     */
    public function isPendingApproval(): bool
    {
        return is_null($this->approved_at);
    }
}
