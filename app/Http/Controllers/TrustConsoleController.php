<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Controllers\FederationController as Federation;
use App\Models\KioskLocation;
use App\Models\Trust;
use App\Models\TrustAuditLog;
use App\Services\Federation\DomainVerifier;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\Invitation;
use App\Services\Federation\PeerUrl;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;
use App\Support\TrustPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/** Trust Console backend (`manage-trusts`): this instance's identity, its trusts, pairing codes and grants. */
class TrustConsoleController extends Controller
{
    public function index()
    {
        $identity = InstanceIdentity::current();

        return response()->json([
            'identity' => [
                'uuid' => $identity->uuid,
                'name' => $identity->name,
                'base_url' => $identity->base_url,
                'public_key' => $identity->public_key,
                'fingerprint' => RequestSigner::fingerprint($identity->public_key),
                'lost' => InstanceIdentity::isLost($identity),
            ],
            'trusts' => Trust::with(['grants', 'approvedBy', 'managedDevice'])->orderBy('name')->get()
                ->map(fn (Trust $t) => $this->present($t))->values(),
            'permissions' => TrustPermission::catalog(),
            'kinds' => Trust::KINDS,
            'locations' => KioskLocation::orderBy('name')->get(['id', 'name']),
            'audit' => TrustAuditLog::orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function createPairingCode(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Trust::KINDS)],
            'name' => 'nullable|string|max:255',
            'grants' => 'array',
            'grants.*' => ['string', Rule::in(TrustPermission::keys())],
            'kiosk_location_id' => 'nullable|exists:kiosk_locations,id',
        ]);
        if (in_array($data['kind'], Trust::CLIENT_KINDS, true) && empty($data['kiosk_location_id'])) {
            return response()->json(['message' => 'Choose the location this device belongs to.'], 422);
        }

        [$code, $row] = TrustService::createPairingCode(
            $data['kind'], $data['grants'] ?? [], $data['name'] ?? null, $data['kiosk_location_id'] ?? null, Auth::id(),
        );

        return response()->json(['code' => $code, 'expires_at' => $row->expires_at], 201);
    }

    /** A connection code for another instance's admin: this address, a one-time code and this key's fingerprint. */
    public function createInvitation(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'grants' => 'array',
            'grants.*' => ['string', Rule::in(TrustPermission::keys())],
        ]);
        $identity = InstanceIdentity::current();
        if (InstanceIdentity::isLost($identity)) {
            return response()->json(['message' => 'Generate a new key first.'], 422);
        }
        if (! $identity->base_url) {
            return response()->json(['message' => 'Set this instance\'s public address first, so the other side knows where to reach it.'], 422);
        }

        [$code, $row] = TrustService::createPairingCode('instance', $data['grants'] ?? [], $data['name'] ?? null, null, Auth::id());

        return response()->json([
            'connection_code' => Invitation::make($identity->base_url, $code, RequestSigner::fingerprint($identity->public_key)),
            'expires_at' => $row->expires_at,
        ], 201);
    }

    /** Use another instance's connection code: verify who answers, pair with it, and record the trust here. */
    public function acceptInvitation(Request $request)
    {
        $data = $request->validate([
            'connection_code' => 'required|string|max:2000',
            'grants' => 'array',
            'grants.*' => ['string', Rule::in(TrustPermission::keys())],
        ]);
        $identity = InstanceIdentity::current();
        if (InstanceIdentity::isLost($identity)) {
            return response()->json(['message' => 'Generate a new key first.'], 422);
        }
        if (! $identity->base_url) {
            return response()->json(['message' => 'Set this instance\'s public address first; the other side records it.'], 422);
        }

        try {
            $invite = Invitation::parse($data['connection_code']);
            $origin = PeerUrl::assertSafe($invite['url']);
            $doc = DomainVerifier::fetchIdentity($origin);
            // The fingerprint came from the other admin, not from the network: this is the check that we reached them.
            if (! hash_equals($invite['fingerprint'], RequestSigner::fingerprint($doc['public_key']))) {
                return response()->json(['message' => 'The instance at that address does not match the connection code. Nothing was paired.'], 422);
            }

            $message = Federation::pairingMessage($invite['code'], $identity->public_key, $identity->uuid);
            $response = Http::timeout(15)->withoutRedirecting()->acceptJson()->post($origin.'/api/federation/v1/pair', [
                'code' => $invite['code'], 'kind' => 'instance', 'uuid' => $identity->uuid, 'name' => $identity->name,
                'public_key' => $identity->public_key, 'base_url' => $identity->base_url,
                'signature' => InstanceIdentity::sign($message),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['message' => 'Could not reach that instance.'], 422);
        }
        if (! $response->successful()) {
            return response()->json(['message' => $response->status() === 422 ? 'The connection code was refused: it is wrong, already used or expired.' : 'The other instance refused the pairing.'], 422);
        }
        if (($response->json('server.public_key') ?? null) !== $doc['public_key'] || ($response->json('server.uuid') ?? null) !== $doc['uuid']) {
            return response()->json(['message' => 'The other instance answered with a different identity than it publishes. Nothing was recorded.'], 422);
        }

        $trust = TrustService::enrollInvited($doc, $origin, $data['grants'] ?? [], $response->json('permissions') ?? [], Auth::id());
        $check = TrustService::refreshOutbound($trust, Auth::id());

        return response()->json([
            'record' => $this->present($trust->fresh(['grants', 'approvedBy', 'managedDevice'])),
            'warning' => $check === null ? 'Paired, but a test call to the other instance failed. Check its address and clock.' : null,
        ], 201);
    }

    public function verifyDomain(Trust $trust)
    {
        $result = DomainVerifier::verify($trust, Auth::id());

        return response()->json($result + ['record' => $this->present($trust->fresh(['grants', 'approvedBy', 'managedDevice']))]);
    }

    public function refreshGrants(Trust $trust)
    {
        if ($trust->kind !== 'instance' || ! $trust->isApproved()) {
            return response()->json(['message' => 'Only an approved instance trust can be refreshed.'], 422);
        }
        if (TrustService::refreshOutbound($trust, Auth::id()) === null) {
            return response()->json(['message' => 'The other instance did not accept the request. It may not have recorded this trust yet.'], 422);
        }

        return response()->json(['record' => $this->present($trust->fresh(['grants', 'approvedBy', 'managedDevice']))]);
    }

    public function setGrants(Request $request, Trust $trust)
    {
        $data = $request->validate([
            'inbound' => 'present|array',
            'inbound.*' => ['string', Rule::in(TrustPermission::keys())],
        ]);
        TrustService::setInboundGrants($trust, $data['inbound'], Auth::id());

        return response()->json(['record' => $this->present($trust->fresh(['grants', 'approvedBy', 'managedDevice']))]);
    }

    public function revoke(Trust $trust)
    {
        TrustService::revoke($trust, Auth::id());

        return response()->json(['record' => $this->present($trust->fresh(['grants', 'approvedBy', 'managedDevice']))]);
    }

    public function updateIdentity(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'base_url' => 'nullable|url|max:255']);
        InstanceIdentity::current()->update(['name' => $data['name'], 'base_url' => $data['base_url'] ? rtrim($data['base_url'], '/') : null]);
        TrustService::audit(null, 'identity_updated', Auth::id(), $data);

        return $this->index();
    }

    /** New key pair. Peers pinned the old public key, so every one of them must re-approve this instance. */
    public function regenerateIdentity(Request $request)
    {
        $request->validate(['confirm' => 'required|in:REGENERATE']);
        InstanceIdentity::regenerate();
        TrustService::audit(null, 'identity_regenerated', Auth::id());

        return $this->index();
    }

    private function present(Trust $trust): array
    {
        return [
            'id' => $trust->id,
            'kind' => $trust->kind,
            'name' => $trust->name,
            'peer_uuid' => $trust->peer_uuid,
            'base_url' => $trust->base_url,
            'status' => $trust->status,
            'fingerprint' => RequestSigner::fingerprint($trust->public_key),
            'auth_method' => $trust->auth_method,
            'approved_by' => $trust->approvedBy?->full_name ?? $trust->approved_by_label,
            'approved_at' => $trust->approved_at,
            'domain_verified_at' => $trust->domain_verified_at,
            'revoked_at' => $trust->revoked_at,
            'last_seen_at' => $trust->last_seen_at,
            'last_seen_ip' => $trust->last_seen_ip,
            'inbound' => $trust->grants->where('direction', 'inbound')->pluck('permission')->values(),
            'outbound' => $trust->grants->where('direction', 'outbound')->pluck('permission')->values(),
            'device_id' => $trust->managedDevice?->id,
        ];
    }
}
