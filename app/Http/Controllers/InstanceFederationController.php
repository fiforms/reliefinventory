<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\InstanceTrustService;
use App\Services\Federation\RequestSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The bootstrap half of the INSTANCE-level trust (`/api/instance/v1/*`): pairing and the session endpoint, the same two calls as
 * FederationController makes for a warehouse, with the same proof (the computer signs the code with the key it submits) and the same
 * session scheme. Pairing is the only unsigned write; the code, made by an instance administrator, is the credential.
 */
class InstanceFederationController extends Controller
{
    public function pair(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'kind' => 'required|in:'.InstanceTrustService::KIND,
            'uuid' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'public_key' => 'required|string|size:43',
            'signature' => 'required|string|max:100',
        ]);

        $attemptsKey = 'instance-pair-attempts:'.$request->ip();
        if ((int) Cache::get($attemptsKey, 0) > 20) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }
        Cache::add($attemptsKey, 0, now()->addMinutes(10));
        Cache::increment($attemptsKey);

        // One generic message for every failure so existing codes aren't leaked.
        $invalid = response()->json(['message' => 'Invalid or expired code.'], 422);
        if (! RequestSigner::verify(FederationController::pairingMessage($data['code'], $data['public_key'], $data['uuid']), $data['signature'], $data['public_key'])) {
            return $invalid;
        }
        $trust = InstanceTrustService::enroll($data['code'], $data, $request->ip());
        if (! $trust) {
            InstanceAuditLog::record('instance.trust_pair_refused', null, ['name' => $data['name'], 'ip' => $request->ip()], 'pairing');

            return $invalid;
        }

        return response()->json(['server' => $this->server(), 'trust_id' => $trust->id, 'permissions' => $trust->permissionList()], 201);
    }

    /** Signed call → a session token, so the next requests need no signature. */
    public function session(Request $request)
    {
        [$token, $ttl] = InstanceTrustService::issueSession($request->attributes->get('instance_trust'));

        return response()->json(['session_token' => $token, 'expires_in' => $ttl]);
    }

    /** What this instance currently lets the calling computer do to it. */
    public function grants(Request $request)
    {
        return response()->json(['permissions' => $request->attributes->get('instance_trust')->permissionList()]);
    }

    /** Who answered, for the person pairing to recognise: this address's public identity (the default warehouse's), else just its name. */
    private function server(): array
    {
        try {
            return InstanceIdentity::publicDocument();
        } catch (Throwable) {
            return ['uuid' => Warehouse::default()->uuid, 'name' => config('app.name'), 'public_key' => ''];
        }
    }
}
