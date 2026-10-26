<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\KioskLocation;
use App\Models\Trust;
use App\Models\TrustAuditLog;
use App\Services\Federation\InstanceIdentity;
use App\Services\Federation\RequestSigner;
use App\Services\Federation\TrustService;
use App\Support\TrustPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
