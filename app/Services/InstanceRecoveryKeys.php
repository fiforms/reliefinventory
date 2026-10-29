<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceRecoveryKey;
use App\Support\RecoveryKey;
use RuntimeException;
use Throwable;

/**
 * The instance's recovery key (scripts/BACKUPS.md "Recovery key"): who made it, whether the administrator confirmed storing it, and the
 * public half the backups seal to. The private half passes through generate() once, on its way to the person, and is stored nowhere: not in
 * the database, not in the audit log (which records the fingerprint only), not in a file.
 *
 * A key can also be made elsewhere (the installer, the RI Launcher, `instance:recovery-key keypair`) and only its public half given to the
 * instance: adopt(), or RI_RECOVERY_PUBLIC_KEY in the environment, taken up the first time anything asks. Then the private half never touches the server at all.
 */
class InstanceRecoveryKeys
{
    /** none: no key, so automatic backups cannot restore encrypted settings. unconfirmed: made here, never confirmed stored. ok. */
    public const STATES = ['none', 'unconfirmed', 'ok'];

    public function row(): ?InstanceRecoveryKey
    {
        $row = InstanceRecoveryKey::find(1);
        if ($row) {
            return $row;
        }
        $fromEnvironment = (string) config('backup.recovery_public_key');
        if ($fromEnvironment !== '' && RecoveryKey::isPublicKey($fromEnvironment)) {
            return $this->store($fromEnvironment, 'environment', 'environment', true);
        }

        return null;
    }

    public function state(): string
    {
        $row = $this->row();

        return ! $row ? 'none' : ($row->acknowledged_at ? 'ok' : 'unconfirmed');
    }

    /** What the pages and commands show. Never anything secret. */
    public function payload(): array
    {
        $row = $this->row();

        return [
            'state' => $this->state(),
            'fingerprint' => $row?->fingerprint,
            'source' => $row?->source,
            'created_by' => $row?->created_by,
            'created_at' => $row?->created_at?->toIso8601String(),
            'acknowledged_at' => $row?->acknowledged_at?->toIso8601String(),
        ];
    }

    /**
     * Make a new pair, keep the public half, and hand back the private half to show once.
     *
     * @return array{private: string, public: string, fingerprint: string}
     *
     * @throws RuntimeException when a key exists and $replace is false
     */
    public function generate(string $actor, bool $replace = false): array
    {
        $existing = $this->row();
        if ($existing && ! $replace) {
            throw new RuntimeException("This instance already has a recovery key ({$existing->fingerprint}). Making a new one means backups from now on need the new key.");
        }
        $pair = RecoveryKey::generate();
        $this->store($pair['public'], 'generated', $actor, false);
        InstanceAuditLog::record($existing ? 'instance.recovery_key_replaced' : 'instance.recovery_key_created', null, [
            'fingerprint' => $pair['fingerprint'], 'replaced' => $existing?->fingerprint,
        ], $actor);

        return $pair;
    }

    /** Take a public key made elsewhere. The person who made it holds the private half, so it counts as confirmed. */
    public function adopt(string $public, string $actor, bool $replace = false): InstanceRecoveryKey
    {
        $public = trim($public);
        if (! RecoveryKey::isPublicKey($public)) {
            throw new RuntimeException('That is not a recovery public key (43 characters of letters, digits, - and _).');
        }
        $existing = InstanceRecoveryKey::find(1);
        if ($existing && ! $replace && $existing->public_key !== $public) {
            throw new RuntimeException("This instance already has a recovery key ({$existing->fingerprint}). Use --replace to change it.");
        }
        $row = $this->store($public, 'adopted', $actor, true);
        InstanceAuditLog::record($existing ? 'instance.recovery_key_replaced' : 'instance.recovery_key_adopted', null, [
            'fingerprint' => $row->fingerprint, 'replaced' => $existing?->fingerprint,
        ], $actor);

        return $row;
    }

    /** The administrator confirms the private key is printed or saved somewhere safe. */
    public function acknowledge(string $actor): void
    {
        $row = InstanceRecoveryKey::find(1) ?? throw new RuntimeException('There is no recovery key to confirm.');
        if (! $row->acknowledged_at) {
            $row->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $actor])->save();
            InstanceAuditLog::record('instance.recovery_key_confirmed', null, ['fingerprint' => $row->fingerprint], $actor);
        }
    }

    /** The public key to seal to, or null when there is none. Never throws: a backup must still be made without it. */
    public function publicKey(): ?string
    {
        try {
            return $this->row()?->public_key;
        } catch (Throwable) {
            return null;
        }
    }

    private function store(string $public, string $source, string $actor, bool $acknowledged): InstanceRecoveryKey
    {
        $row = InstanceRecoveryKey::find(1) ?? (new InstanceRecoveryKey)->forceFill(['id' => 1]);
        $row->forceFill([
            'public_key' => $public, 'fingerprint' => RecoveryKey::fingerprint($public), 'source' => $source, 'created_by' => $actor,
            'acknowledged_at' => $acknowledged ? now() : null, 'acknowledged_by' => $acknowledged ? $actor : null,
        ])->save();

        return $row->refresh();
    }
}
