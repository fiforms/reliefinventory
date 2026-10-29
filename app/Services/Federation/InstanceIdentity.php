<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use App\Models\Control\Warehouse;
use App\Models\FederationIdentity;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * This instance's federation identity. The key pair is created the first time anything asks for it.
 * If the private key was blanked by a restore onto a different APP_KEY, current() still returns the row
 * (so the console can say so) but isLost() is true and signing refuses until regenerate() is called.
 */
class InstanceIdentity
{
    public static function current(): FederationIdentity
    {
        $identity = FederationIdentity::query()->whereNull('tenant_id')->first();

        return $identity ?? self::create();
    }

    public static function isLost(?FederationIdentity $identity = null): bool
    {
        $identity ??= self::current();
        try {
            return blank($identity->private_key);
        } catch (Throwable) {
            return true;
        }
    }

    /** New key pair (and a new public key, so every peer must re-approve it). The uuid is kept. */
    public static function regenerate(): FederationIdentity
    {
        $identity = self::current();
        [$public, $secret] = self::newKeyPair();
        $identity->forceFill(['public_key' => $public, 'private_key' => $secret])->save();

        return $identity;
    }

    public static function publicDocument(): array
    {
        $identity = self::current();

        return [
            'protocol' => 1,
            'uuid' => $identity->uuid,
            'name' => $identity->name,
            'url' => $identity->base_url,
            'public_key' => $identity->public_key,
            'fingerprint' => RequestSigner::fingerprint($identity->public_key),
            // Which warehouse this is, and whether its address is shared with other warehouses: then the domain
            // proves only that the hosting party controls it, not this warehouse's organization (§4, DomainVerifier).
            'warehouse' => Warehouse::current()->id,
            'shared_host' => app(WarehouseContext::class)->multiple() && ! Warehouse::current()->ownsHost(request()->getHost()),
        ];
    }

    public static function sign(string $canonical): string
    {
        $identity = self::current();
        if (self::isLost($identity)) {
            throw new \RuntimeException('This instance\'s federation key is lost; regenerate it first.');
        }

        return RequestSigner::sign($canonical, $identity->private_key);
    }

    private static function create(): FederationIdentity
    {
        [$public, $secret] = self::newKeyPair();

        return FederationIdentity::create([
            'uuid' => (string) Str::uuid(),
            'name' => (string) config('app.name'),
            'base_url' => Warehouse::current()->machineUrl() ?: null,
            'public_key' => $public,
            'private_key' => $secret,
        ]);
    }

    /** @return array{0: string, 1: string} base64url public, secret */
    private static function newKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            RequestSigner::b64(sodium_crypto_sign_publickey($pair)),
            RequestSigner::b64(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
