<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use App\Models\Trust;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Domain verification: fetch the peer's published identity over TLS from the address it claims and check it
 * is the same key we pinned. Passing means whoever controls that domain's web server holds the key we trust.
 */
class DomainVerifier
{
    /** The identity document published at an address (used for invitations and for verification). */
    public static function fetchIdentity(string $origin): array
    {
        $origin = PeerUrl::assertSafe($origin);
        $response = Http::timeout(10)->withoutRedirecting()->acceptJson()->get($origin.'/.well-known/ri-instance.json');
        $doc = $response->json();
        if (! $response->successful() || ! is_array($doc) || empty($doc['public_key']) || empty($doc['uuid'])) {
            throw new InvalidArgumentException('That address did not answer like a Relief Inventory instance.');
        }
        if (strlen(RequestSigner::unb64($doc['public_key'])) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('That instance published an unusable key.');
        }

        return $doc;
    }

    /** @return array{ok: bool, message: string} */
    public static function verify(Trust $trust, ?int $actor = null): array
    {
        if ($trust->kind !== 'instance' || ! $trust->base_url) {
            return ['ok' => false, 'message' => 'Only another instance with a public address can be domain verified.'];
        }

        try {
            $doc = self::fetchIdentity($trust->base_url);
            $ok = hash_equals($trust->public_key, (string) $doc['public_key']) && ($doc['uuid'] ?? null) === $trust->peer_uuid;
            $message = $ok ? 'The address publishes the key this trust is pinned to.' : 'The address publishes a different key or identity than this trust.';
            // Several warehouses on one address: the domain belongs to whoever hosts them, so it can't vouch for this
            // one's organization (MULTI_WAREHOUSE.md §4). Only a warehouse with a hostname of its own can be verified.
            if ($ok && ($doc['shared_host'] ?? false) === true) {
                $ok = false;
                $message = 'That address is shared by several warehouses, so its domain can\'t vouch for this one. The trust stays approved by an administrator.';
            }
        } catch (Throwable $e) {
            $ok = false;
            $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not reach that address.';
        }

        $trust->forceFill($ok
            ? ['domain_verified_at' => now(), 'auth_method' => 'domain_verified']
            : ['domain_verified_at' => null, 'auth_method' => $trust->auth_method === 'domain_verified' ? 'pairing_code' : $trust->auth_method]
        )->save();
        TrustService::audit($trust, $ok ? 'domain_verified' : 'domain_verify_failed', $actor, ['message' => $message]);

        return ['ok' => $ok, 'message' => $message];
    }
}
