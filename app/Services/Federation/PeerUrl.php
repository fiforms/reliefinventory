<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use InvalidArgumentException;

/**
 * A peer's address as an origin (scheme://host[:port]). Anything an admin or a peer hands us that we are about
 * to fetch goes through here first, so a trust cannot be used to make this server probe its own network.
 * (A host's DNS can still change between this check and the request; the fetch itself never follows redirects.)
 */
class PeerUrl
{
    public static function normalize(string $input): string
    {
        $input = trim($input);
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new InvalidArgumentException('That is not a valid address.');
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @throws InvalidArgumentException when the origin may not be contacted under this instance's settings */
    public static function assertSafe(string $origin): string
    {
        $origin = self::normalize($origin);
        $parts = parse_url($origin);

        if ($parts['scheme'] !== 'https' && ! config('federation.allow_insecure_http')) {
            throw new InvalidArgumentException('Peers must be reached over https.');
        }
        if (! config('federation.allow_private_hosts')) {
            $ips = filter_var($parts['host'], FILTER_VALIDATE_IP) ? [$parts['host']] : (gethostbynamel($parts['host']) ?: []);
            if (! $ips) {
                throw new InvalidArgumentException('That address does not resolve.');
            }
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new InvalidArgumentException('That address points at a private or reserved network.');
                }
            }
        }

        return $origin;
    }

    /** Host with any non-default port, as the receiving server sees it in the Host header. */
    public static function hostHeader(string $origin): string
    {
        $parts = parse_url($origin);

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
