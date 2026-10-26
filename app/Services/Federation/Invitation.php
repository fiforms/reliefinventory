<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use InvalidArgumentException;

/**
 * A connection code an admin gives to another instance's admin: `RIFED1-` + base64url JSON of this instance's
 * address, a one-time pairing code and this instance's key fingerprint. The fingerprint is what lets the other
 * side tell it reached the right instance (the address alone would trust whatever answers there).
 */
class Invitation
{
    public const PREFIX = 'RIFED1-';

    public static function make(string $url, string $code, string $fingerprint): string
    {
        return self::PREFIX.RequestSigner::b64(json_encode(['u' => $url, 'c' => $code, 'f' => $fingerprint]));
    }

    /** @return array{url: string, code: string, fingerprint: string} */
    public static function parse(string $text): array
    {
        $text = trim($text);
        $data = str_starts_with($text, self::PREFIX)
            ? json_decode(RequestSigner::unb64(substr($text, strlen(self::PREFIX))), true)
            : null;
        if (! is_array($data) || empty($data['u']) || empty($data['c']) || empty($data['f'])) {
            throw new InvalidArgumentException('That is not a valid connection code.');
        }

        return ['url' => (string) $data['u'], 'code' => (string) $data['c'], 'fingerprint' => (string) $data['f']];
    }
}
