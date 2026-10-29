<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstancePairingCode;
use App\Models\Control\InstanceTrust;
use App\Models\TrustPairingCode;
use App\Support\InstancePermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Everything that creates or changes an instance-level trust (a computer allowed to take whole-instance backups) goes through here, so each
 * change is audited (instance_audit_log) and takes effect at once. Mirrors TrustService for the per-warehouse trusts, and reuses its
 * code alphabet and the pairing proof (FederationController::pairingMessage), so the RI Launcher pairs the same way with either. Session
 * tokens are cache entries holding (trust id, session_epoch): re-pairing or revoking bumps the epoch, and an old token stops matching.
 */
class InstanceTrustService
{
    /** The `kind` a computer states when it pairs with the instance (the warehouse kinds are App\Models\Trust::KINDS). */
    public const KIND = 'instance_backup';

    public const SESSION_PREFIX = 'RII-';

    /** @return array{0: string, 1: InstancePairingCode} the plaintext code (shown once, as XXXXX-XXXXX) and its row */
    public static function createPairingCode(?string $nameHint, string $createdBy): array
    {
        $plain = TrustService::newCode();
        $row = InstancePairingCode::create([
            'code_hash' => TrustPairingCode::hash($plain),
            'permissions' => [InstancePermission::BACKUP],
            'name_hint' => $nameHint,
            'created_by' => $createdBy,
            'expires_at' => now()->addMinutes((int) config('federation.pairing_code_minutes')),
        ]);
        InstanceAuditLog::record('instance.trust_code_created', null, ['name_hint' => $nameHint], $createdBy);

        return [substr($plain, 0, 5).'-'.substr($plain, 5), $row];
    }

    /**
     * Redeem a code for a computer's public key (the caller has already checked it holds the key). The same peer uuid pairing again
     * re-attaches its trust with a new code, and a different key is recorded as a key change.
     *
     * @param  array{uuid: string, name: string, public_key: string}  $peer
     * @return InstanceTrust|null null when the code is unknown, used or expired
     */
    public static function enroll(string $code, array $peer, ?string $ip = null): ?InstanceTrust
    {
        return (new InstanceTrust)->getConnection()->transaction(function () use ($code, $peer, $ip) {
            $row = InstancePairingCode::usable()->where('code_hash', TrustPairingCode::hash($code))->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $trust = InstanceTrust::where('peer_uuid', $peer['uuid'])->first();
            $keyChanged = $trust && $trust->public_key !== $peer['public_key'];
            $trust ??= new InstanceTrust(['peer_uuid' => $peer['uuid']]);
            $trust->forceFill([
                'name' => $peer['name'] ?: ($row->name_hint ?: $peer['uuid']),
                'public_key' => $peer['public_key'],
                'status' => 'approved',
                'permissions' => InstancePermission::clean($row->permissions ?? []),
                'approved_by' => $row->created_by,
                'approved_at' => now(),
                'revoked_at' => null,
                'session_epoch' => (int) $trust->session_epoch + 1,
                'last_seen_ip' => $ip,
            ])->save();
            $row->forceFill(['used_at' => now(), 'trust_id' => $trust->id])->save();
            InstanceAuditLog::record($keyChanged ? 'instance.trust_key_changed' : 'instance.trust_enrolled', null, ['trust' => $trust->id, 'name' => $trust->name], $row->created_by);

            return $trust;
        });
    }

    public static function revoke(InstanceTrust $trust, string $actor): void
    {
        $trust->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'session_epoch' => $trust->session_epoch + 1])->save();
        InstanceAuditLog::record('instance.trust_revoked', null, ['trust' => $trust->id, 'name' => $trust->name], $actor);
    }

    /** Opaque session token for a trust that just proved its key with a signed request. @return array{0: string, 1: int} */
    public static function issueSession(InstanceTrust $trust): array
    {
        $ttl = (int) config('federation.session_ttl_seconds');
        $token = self::SESSION_PREFIX.Str::random(48);
        Cache::put(self::sessionKey($token), [$trust->id, (int) $trust->session_epoch], $ttl);

        return [$token, $ttl];
    }

    public static function trustForSession(string $token): ?InstanceTrust
    {
        if (! str_starts_with($token, self::SESSION_PREFIX)) {
            return null;
        }
        $entry = Cache::get(self::sessionKey($token));
        if (! is_array($entry)) {
            return null;
        }
        $trust = InstanceTrust::find($entry[0]);

        return $trust && (int) $trust->session_epoch === $entry[1] ? $trust : null;
    }

    private static function sessionKey(string $token): string
    {
        return 'instance-trust-session:'.hash('sha256', $token);
    }
}
