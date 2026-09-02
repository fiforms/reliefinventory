<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * Turns a caught mail-send exception into a short, safe-to-display string.
 * Resend's own SDK exception (Resend\Exceptions\ErrorException) usually
 * arrives wrapped inside Symfony Mailer's TransportException — that outer
 * exception's own message is a generic "Unable to send..." wrapper, so this
 * walks the ->getPrevious() chain looking for the real Resend error message
 * first, since that's the one actually useful to a user or admin (e.g.
 * "Invalid `to` field...").
 */
class EmailFailureMessage
{
    public static function describe(\Throwable $e): string
    {
        $current = $e;

        while ($current !== null) {
            if (str_contains($current::class, 'Resend\\')) {
                return $current->getMessage();
            }

            $current = $current->getPrevious();
        }

        return $e->getMessage();
    }
}
