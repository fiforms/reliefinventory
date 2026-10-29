<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\VerifiesSignedRequests;
use App\Models\Control\InstanceTrust;
use App\Services\Federation\InstanceTrustService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticates a computer paired to the INSTANCE (App\Models\Control\InstanceTrust): an Ed25519-signed request or the session token a signed
 * call earned (Authorization: Bearer RII-…), exactly as TrustAuth does for a warehouse's trusts (VerifiesSignedRequests is the shared check).
 * Usage: `instance-trust:instance.backup`, `instance-trust:,signed` (a signature is required: the session endpoint) or plain `instance-trust`.
 * 401 means only "unknown, revoked or badly signed" (the Launcher un-pairs itself on it), so a clock problem is a 400 with X-RI-Server-Time.
 * Status and permissions are read live on every request. A warehouse trust is in another table and can never pass here, nor the reverse.
 */
class InstanceTrustAuth
{
    use VerifiesSignedRequests;

    public function handle(Request $request, Closure $next, string ...$options)
    {
        $signedOnly = in_array('signed', $options, true);
        $permission = collect($options)->first(fn ($o) => $o !== '' && $o !== 'signed');

        $bearer = $request->bearerToken();
        if ($bearer && ! $signedOnly) {
            $trust = InstanceTrustService::trustForSession($bearer);
        } else {
            $trust = $this->verifySignedRequest($request, fn (string $peer) => InstanceTrust::where('peer_uuid', $peer)->first(), 'instance-trust-nonce');
            if ($trust instanceof JsonResponse) {
                return $trust;
            }
        }

        if (! $trust || ! $trust->isApproved()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($permission && ! $trust->allows($permission)) {
            return response()->json(['message' => 'This trust does not grant that.'], 403);
        }

        if (! $trust->last_seen_at || $trust->last_seen_at->lt(now()->subMinute())) {
            $trust->forceFill(['last_seen_at' => now(), 'last_seen_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('instance_trust', $trust);

        return $next($request);
    }
}
