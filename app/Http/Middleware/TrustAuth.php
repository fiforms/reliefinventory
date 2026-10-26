<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use App\Models\Trust;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Authenticates a trusted peer: either an Ed25519-signed request (X-RI-Trust / X-RI-Timestamp / X-RI-Nonce /
 * X-RI-Signature, see RequestSigner) or the session token a signed call earned (Authorization: Bearer RIS-…).
 * Usage: `trust-auth:permission.key` (peer must hold that inbound grant), `trust-auth:permission.key,signed`
 * or just `trust-auth:,signed` (a signature is required — the session endpoint itself).
 *
 * 401 means only "unknown, revoked or badly signed" — clients wipe their pairing on it, so a clock problem
 * is a 400 carrying X-RI-Server-Time instead. Trust status and grants are read live on every request.
 */
class TrustAuth
{
    public function handle(Request $request, Closure $next, string ...$options)
    {
        $signedOnly = in_array('signed', $options, true);
        $permission = collect($options)->first(fn ($o) => $o !== '' && $o !== 'signed');

        if (InstanceIdentity::isLost()) {
            return response()->json(['message' => 'This instance has no federation key.'], 503);
        }

        $bearer = $request->bearerToken();
        if ($bearer && ! $signedOnly) {
            $trust = TrustService::trustForSession($bearer);
        } else {
            $result = $this->verifySignature($request);
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $trust = $result;
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
        $request->attributes->set('trust', $trust);
        if ($trust->isClient() && ($device = $trust->managedDevice) && ! $device->revoked_at) {
            $request->attributes->set('managed_device', $device);
        }

        return $next($request);
    }

    /** @return Trust|JsonResponse|null */
    private function verifySignature(Request $request)
    {
        $peer = (string) $request->header('X-RI-Trust');
        $timestamp = (string) $request->header('X-RI-Timestamp');
        $nonce = (string) $request->header('X-RI-Nonce');
        $signature = (string) $request->header('X-RI-Signature');
        if ($peer === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return null;
        }

        $skew = abs(time() - (int) $timestamp);
        if ($skew > (int) config('federation.max_skew_seconds')) {
            return response()->json(['message' => 'Clock difference too large.'], 400)
                ->header('X-RI-Server-Time', (string) time());
        }

        $trust = Trust::where('identity_id', InstanceIdentity::current()->id)->where('peer_uuid', $peer)->first();
        if (! $trust) {
            return null;
        }

        $canonical = RequestSigner::canonical(
            $request->method(), $request->getRequestUri(), $request->getHttpHost(), (int) $timestamp, $nonce, $request->getContent(),
        );
        if (! RequestSigner::verify($canonical, $signature, $trust->public_key)) {
            return null;
        }

        // Only a correctly signed request may use up a nonce, so a stranger can't burn a peer's nonces.
        if (! Cache::add('trust-nonce:'.$trust->id.':'.hash('sha256', $nonce), 1, (int) config('federation.nonce_ttl_seconds'))) {
            return null;
        }

        return $trust;
    }
}
