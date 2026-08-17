<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects PINs that are trivially guessable: more than 2 of the same digit
 * in a row (111xx), or more than 3 digits in a row that count sequentially
 * up or down (1234x, x4321). Applied on top of the `digits:5` rule, which
 * only checks shape, not strength.
 */
class SecurePin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;

        if (! preg_match('/^\d+$/', $pin)) {
            return;
        }

        $repeatRun = $upRun = $downRun = 1;
        $maxRepeatRun = $maxUpRun = $maxDownRun = 1;

        for ($i = 1; $i < strlen($pin); $i++) {
            $prev = (int) $pin[$i - 1];
            $cur = (int) $pin[$i];

            $repeatRun = $cur === $prev ? $repeatRun + 1 : 1;
            $upRun = $cur === $prev + 1 ? $upRun + 1 : 1;
            $downRun = $cur === $prev - 1 ? $downRun + 1 : 1;

            $maxRepeatRun = max($maxRepeatRun, $repeatRun);
            $maxUpRun = max($maxUpRun, $upRun);
            $maxDownRun = max($maxDownRun, $downRun);
        }

        if ($maxRepeatRun > 2) {
            $fail('PIN cannot repeat the same digit more than twice in a row.');

            return;
        }

        if ($maxUpRun > 3 || $maxDownRun > 3) {
            $fail('PIN cannot contain more than 3 sequential digits in a row (e.g. 1234 or 4321).');
        }
    }
}
