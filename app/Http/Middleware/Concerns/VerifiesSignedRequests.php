<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware\Concerns;

use App\Services\Federation\RequestSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The one check of an Ed25519-signed request (X-RI-Trust / X-RI-Timestamp / X-RI-Nonce / X-RI-Signature, see RequestSigner), shared by the
 * per-warehouse trusts (TrustAuth) and the instance-level ones (InstanceTrustAuth) so the two cannot drift apart: the same canonical string,
 * clock window and one-use nonces, whatever table the peer's key is found in.
 */
trait VerifiesSignedRequests
{
    /**
     * @param  callable(string): ?Model  $find  the trust for the peer uuid in X-RI-Trust (it must have a `public_key` and an `id`)
     * @param  string  $noncePrefix  keeps one kind of trust's used nonces apart from another's
     * @return Model|JsonResponse|null the trust; a 400 (clock too far off, with X-RI-Server-Time: never a 401, which clients read as "un-paired"); or null for unknown / badly signed / replayed
     */
    protected function verifySignedRequest(Request $request, callable $find, string $noncePrefix)
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

        $trust = $find($peer);
        if (! $trust) {
            return null;
        }

        $canonical = RequestSigner::canonical(
            // The URI as sent, `/w/<id>` prefix included (ResolveWarehouse strips it for routing only): a signature made
            // for one warehouse's address must not pass at another's.
            $request->method(), $request->attributes->get('ri.original_uri', $request->getRequestUri()), $request->getHttpHost(), (int) $timestamp, $nonce, $request->getContent(),
        );
        if (! RequestSigner::verify($canonical, $signature, $trust->public_key)) {
            return null;
        }

        // Only a correctly signed request may use up a nonce, so a stranger can't burn a peer's nonces.
        if (! Cache::add($noncePrefix.':'.$trust->id.':'.hash('sha256', $nonce), 1, (int) config('federation.nonce_ttl_seconds'))) {
            return null;
        }

        return $trust;
    }
}
