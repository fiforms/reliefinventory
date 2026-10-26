<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ManagedDevice;
use App\Models\Trust;
use App\Models\TrustPairingCode;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The unauthenticated and bootstrap half of federation: this instance's public identity, pairing, and the
 * session endpoint. Pairing is the only unsigned write; the code is the credential and the peer must also
 * prove it holds the private key for the public key it submits.
 */
class FederationController extends Controller
{
    /** The string a peer signs to prove possession of its key when pairing. */
    public static function pairingMessage(string $code, string $publicKey, string $uuid): string
    {
        return "ri-pair-v1\n".TrustPairingCode::normalize($code)."\n".$publicKey."\n".$uuid;
    }

    public function identity()
    {
        return response()->json(InstanceIdentity::publicDocument());
    }

    public function pair(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'kind' => 'required|in:'.implode(',', Trust::KINDS),
            'uuid' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'public_key' => 'required|string|size:43',
            'signature' => 'required|string|max:100',
            'base_url' => 'nullable|url|max:255',
            'mac_address' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
        ]);
        if (InstanceIdentity::isLost()) {
            return response()->json(['message' => 'This instance has no federation key.'], 503);
        }

        $attemptsKey = 'trust-pair-attempts:'.$request->ip();
        if ((int) Cache::get($attemptsKey, 0) > 20) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }
        Cache::add($attemptsKey, 0, now()->addMinutes(10));
        Cache::increment($attemptsKey);

        // One generic message for every failure so existing codes aren't leaked.
        $invalid = response()->json(['message' => 'Invalid or expired code.'], 422);
        if (! RequestSigner::verify(self::pairingMessage($data['code'], $data['public_key'], $data['uuid']), $data['signature'], $data['public_key'])) {
            return $invalid;
        }
        $trust = TrustService::enroll($data['code'], $data, $request->ip());
        if (! $trust) {
            return $invalid;
        }

        $device = $trust->managedDevice;

        return response()->json([
            'server' => InstanceIdentity::publicDocument(),
            'trust_id' => $trust->id,
            'permissions' => $trust->inboundPermissions(),
            'device_id' => $device?->id,
            'entity_id' => $device?->kiosk_location_id,
            'entity_name' => $device?->location?->name,
            'sibling_hostnames' => $device
                ? ManagedDevice::where('kiosk_location_id', $device->kiosk_location_id)
                    ->where('id', '!=', $device->id)->whereNotNull('hostname')->pluck('hostname')
                : [],
            'language' => $device?->language,
            'language_revision' => $device?->language_revision,
        ], 201);
    }

    /** Signed call → a session token, so the next requests need no signature. */
    public function session(Request $request)
    {
        [$token, $ttl] = TrustService::issueSession($request->attributes->get('trust'));

        return response()->json(['session_token' => $token, 'expires_in' => $ttl]);
    }

    /** What this instance currently lets the calling peer do to it. */
    public function grants(Request $request)
    {
        return response()->json(['permissions' => $request->attributes->get('trust')->inboundPermissions()]);
    }
}
