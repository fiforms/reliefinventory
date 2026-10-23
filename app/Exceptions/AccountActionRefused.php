<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Exceptions;

/**
 * A User Administration account action refused for one particular person
 * (UserAdminController) — answered with $status for a single account, or
 * listed as skipped by a bulk run.
 */
class AccountActionRefused extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
