<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use App\Models\ManagedDevice;
use App\Models\Trust;
use App\Models\TrustAuditLog;
use App\Models\TrustPairingCode;
use App\Support\TrustPermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that creates or changes a trust goes through here so each change is audited and takes effect
 * at once. Session tokens are cache entries holding (trust id, session_epoch): a re-approval or a revoke
 * bumps the epoch, so an old session stops matching without having to track it.
 */
class TrustService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const SESSION_PREFIX = 'RIS-';

    /** @return array{0: string, 1: TrustPairingCode} the plaintext code (shown once) and its row */
    public static function createPairingCode(string $kind, array $grants, ?string $nameHint, ?int $kioskLocationId, ?int $createdBy): array
    {
        $plain = '';
        for ($i = 0; $i < 10; $i++) {
            $plain .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        $row = TrustPairingCode::create([
            'code_hash' => TrustPairingCode::hash($plain),
            'kind' => $kind,
            'name_hint' => $nameHint,
            'grants' => self::cleanPermissions($grants),
            'kiosk_location_id' => $kioskLocationId,
            'created_by' => $createdBy,
            'expires_at' => now()->addMinutes((int) config('federation.pairing_code_minutes')),
        ]);

        return [substr($plain, 0, 5).'-'.substr($plain, 5), $row];
    }

    /**
     * Redeem a code for a peer's public key. The caller has already checked the peer owns the key. A second
     * enrollment with the same peer uuid re-attaches the trust (new code required, audited); a different key
     * is allowed only that way and is recorded as a key change.
     *
     * @param  array{kind: string, uuid: string, name: string, public_key: string, base_url?: ?string, mac_address?: ?string, language?: ?string}  $peer
     * @return Trust|null null when the code is unknown, used, expired or for another kind
     */
    public static function enroll(string $code, array $peer, ?string $ip = null): ?Trust
    {
        return DB::transaction(function () use ($code, $peer, $ip) {
            $row = TrustPairingCode::usable()->where('code_hash', TrustPairingCode::hash($code))->lockForUpdate()->first();
            if (! $row || ! self::codeFits($row->kind, $peer['kind'])) {
                return null;
            }

            $identity = InstanceIdentity::current();
            $trust = Trust::where('identity_id', $identity->id)->where('peer_uuid', $peer['uuid'])->first();
            $keyChanged = $trust && $trust->public_key !== $peer['public_key'];
            $trust ??= new Trust(['identity_id' => $identity->id, 'peer_uuid' => $peer['uuid']]);
            $trust->fill([
                'kind' => $peer['kind'],
                'name' => $peer['name'] ?: ($row->name_hint ?: $peer['uuid']),
                'public_key' => $peer['public_key'],
                'base_url' => $peer['base_url'] ?? null,
                'status' => 'approved',
                'auth_method' => 'pairing_code',
            ]);
            $trust->approved_by_person_id = $row->created_by;
            $trust->approved_at = now();
            $trust->revoked_at = null;
            $trust->session_epoch = (int) $trust->session_epoch + 1;
            $trust->last_seen_ip = $ip;
            $trust->save();

            self::replaceGrants($trust, 'inbound', $row->grants ?? []);
            if ($trust->isClient()) {
                self::attachDevice($trust, $row->kiosk_location_id, $peer);
            }
            $row->update(['used_at' => now(), 'trust_id' => $trust->id]);
            self::audit($trust, $keyChanged ? 'key_changed' : 'enrolled', $row->created_by, [
                'method' => 'pairing_code', 'grants' => $row->grants,
            ]);

            return $trust;
        });
    }

    /**
     * The accepting side of an invitation: record the instance that invited us. `$serverDoc` is the identity it
     * published (already checked against the invitation's fingerprint); `$outbound` is what it said we may do to
     * it. Domain-verified when the address is https, since that fetch was TLS-checked against the same key.
     */
    public static function enrollInvited(array $serverDoc, string $origin, array $inbound, array $outbound, ?int $actor): Trust
    {
        return DB::transaction(function () use ($serverDoc, $origin, $inbound, $outbound, $actor) {
            $identity = InstanceIdentity::current();
            $trust = Trust::where('identity_id', $identity->id)->where('peer_uuid', $serverDoc['uuid'])->first();
            $keyChanged = $trust && $trust->public_key !== $serverDoc['public_key'];
            $trust ??= new Trust(['identity_id' => $identity->id, 'peer_uuid' => $serverDoc['uuid']]);
            $verified = str_starts_with($origin, 'https://');
            $trust->fill([
                'kind' => 'instance',
                'name' => $serverDoc['name'] ?? $origin,
                'public_key' => $serverDoc['public_key'],
                'base_url' => $origin,
                'status' => 'approved',
                'auth_method' => $verified ? 'domain_verified' : 'pairing_code',
            ]);
            $trust->approved_by_person_id = $actor;
            $trust->approved_at = now();
            $trust->domain_verified_at = $verified ? now() : null;
            $trust->revoked_at = null;
            $trust->session_epoch = (int) $trust->session_epoch + 1;
            $trust->save();

            self::replaceGrants($trust, 'inbound', $inbound);
            self::replaceGrants($trust, 'outbound', $outbound);
            self::audit($trust, $keyChanged ? 'key_changed' : 'enrolled', $actor, [
                'method' => 'invitation', 'inbound' => self::cleanPermissions($inbound), 'outbound' => self::cleanPermissions($outbound),
            ]);

            return $trust;
        });
    }

    /** Ask the peer what we may do to it and mirror that as our outbound grants. @return list<string>|null null if unreachable */
    public static function refreshOutbound(Trust $trust, ?int $actor = null): ?array
    {
        try {
            $response = FederationClient::for($trust)->get('/grants');
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json('permissions'))) {
            return null;
        }
        $permissions = self::cleanPermissions($response->json('permissions'));
        self::replaceGrants($trust, 'outbound', $permissions);
        self::audit($trust, 'outbound_refreshed', $actor, ['outbound' => $permissions]);

        return $permissions;
    }

    /** A `client` code (made on the Devices page) pairs any client app; other codes are for one kind. */
    private static function codeFits(string $codeKind, string $peerKind): bool
    {
        return $codeKind === $peerKind || ($codeKind === 'client' && in_array($peerKind, Trust::CLIENT_KINDS, true));
    }

    public static function setInboundGrants(Trust $trust, array $permissions, ?int $actor): void
    {
        $before = $trust->inboundPermissions();
        self::replaceGrants($trust, 'inbound', $permissions);
        self::audit($trust, 'grants_changed', $actor, ['before' => $before, 'after' => $trust->fresh()->inboundPermissions()]);
    }

    public static function revoke(Trust $trust, ?int $actor): void
    {
        DB::transaction(function () use ($trust, $actor) {
            $trust->update(['status' => 'revoked', 'revoked_at' => now(), 'session_epoch' => $trust->session_epoch + 1]);
            $device = $trust->managedDevice;
            if ($device) {
                $device->forceFill(['revoked_at' => now(), 'wake_channel' => null, 'session_token_hash' => null])->save();
            }
            self::audit($trust, 'revoked', $actor);
        });
    }

    /** Opaque session token for a trust that just proved its key with a signed request. */
    public static function issueSession(Trust $trust): array
    {
        $ttl = (int) config('federation.session_ttl_seconds');
        $token = self::SESSION_PREFIX.Str::random(48);
        Cache::put(self::sessionKey($token), [$trust->id, (int) $trust->session_epoch], $ttl);

        return [$token, $ttl];
    }

    public static function trustForSession(string $token): ?Trust
    {
        if (! str_starts_with($token, self::SESSION_PREFIX)) {
            return null;
        }
        $entry = Cache::get(self::sessionKey($token));
        if (! is_array($entry)) {
            return null;
        }
        $trust = Trust::find($entry[0]);

        return $trust && (int) $trust->session_epoch === $entry[1] ? $trust : null;
    }

    public static function audit(?Trust $trust, string $event, ?int $actor = null, array $detail = []): void
    {
        TrustAuditLog::create([
            'trust_id' => $trust?->id, 'event' => $event, 'actor_person_id' => $actor, 'detail' => $detail ?: null,
        ]);
    }

    /** @return list<string> only known permission keys, unique */
    public static function cleanPermissions(array $permissions): array
    {
        return array_values(array_unique(array_intersect($permissions, TrustPermission::keys())));
    }

    private static function replaceGrants(Trust $trust, string $direction, array $permissions): void
    {
        $permissions = self::cleanPermissions($permissions);
        $trust->grants()->where('direction', $direction)->whereNotIn('permission', $permissions)->delete();
        foreach ($permissions as $permission) {
            $trust->grants()->firstOrCreate(['permission' => $permission, 'direction' => $direction]);
        }
        $trust->unsetRelation('grants');
    }

    private static function attachDevice(Trust $trust, ?int $kioskLocationId, array $peer): void
    {
        $device = ManagedDevice::where('device_uuid', $trust->peer_uuid)->first()
            ?? new ManagedDevice(['update_channel' => 'stable']);
        $device->device_uuid = $trust->peer_uuid;
        $device->fill(['name' => $trust->name, 'kiosk_location_id' => $kioskLocationId]);
        $device->mac_address = $peer['mac_address'] ?? $device->mac_address;
        if ($device->language === null && ! empty($peer['language'])) {
            $device->language = $peer['language']; // a hint only; never overrides an admin's choice
            $device->language_revision = 1;
        }
        $device->trust_id = $trust->id;
        $device->revoked_at = null;
        $device->paired_at = now();
        $device->token_hash = null; // a trust-paired device has no bearer token
        // A re-pair rotates the wake-up channel and ends the browser session the previous pairing had.
        $device->wake_channel = null;
        $device->session_token_hash = null;
        $device->save();
    }

    private static function sessionKey(string $token): string
    {
        return 'trust-session:'.hash('sha256', $token);
    }
}
