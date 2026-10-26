<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

/**
 * Canonical string + Ed25519 signing for federation requests. Every client (PHP, Rust, Python) must
 * produce byte-identical canonical strings; tests/Fixtures/federation-signing-vectors.json is the shared
 * check, so change the format only together with that file and every client.
 *
 * canonical = METHOD \n PATH?QUERY \n HOST \n TIMESTAMP \n NONCE \n SHA256-hex(body)
 * (METHOD upper-case, HOST lower-case with any non-default port, PATH?QUERY exactly as sent, a GET signs the
 * empty body). Keys and signatures are base64url without padding.
 */
class RequestSigner
{
    public static function canonical(string $method, string $pathAndQuery, string $host, int $timestamp, string $nonce, string $body = ''): string
    {
        return implode("\n", [
            strtoupper($method),
            $pathAndQuery,
            strtolower($host),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /** @param string $secretKey base64url libsodium secret key (64 bytes) */
    public static function sign(string $canonical, string $secretKey): string
    {
        return self::b64(sodium_crypto_sign_detached($canonical, self::unb64($secretKey)));
    }

    public static function verify(string $canonical, string $signature, string $publicKey): bool
    {
        $sig = self::unb64($signature);
        $pub = self::unb64($publicKey);
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $canonical, $pub);
    }

    public const BEARER_PREFIX = 'RIK-';

    /** What a key-signed bearer credential signs: who, for which server (audience), and when. */
    public static function bearerMessage(string $uuid, string $host, int $issuedAt, int $expiresAt): string
    {
        return implode("\n", ['ri-bearer-v1', $uuid, strtolower($host), (string) $issuedAt, (string) $expiresAt]);
    }

    /** `RIK-<b64url uuid>.<iat>.<exp>.<signature>` — minted by the client; the server only ever verifies it. */
    public static function mintBearer(string $uuid, string $host, int $issuedAt, int $expiresAt, string $secretKey): string
    {
        $signature = self::sign(self::bearerMessage($uuid, $host, $issuedAt, $expiresAt), $secretKey);

        return self::BEARER_PREFIX.self::b64($uuid).".{$issuedAt}.{$expiresAt}.{$signature}";
    }

    /** @return array{uuid: string, iat: int, exp: int, sig: string}|null null if it is not shaped like a credential */
    public static function parseBearer(string $token): ?array
    {
        if (! str_starts_with($token, self::BEARER_PREFIX)) {
            return null;
        }
        $parts = explode('.', substr($token, strlen(self::BEARER_PREFIX)));
        if (count($parts) !== 4 || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return null;
        }
        $uuid = self::unb64($parts[0]);

        return $uuid === '' ? null : ['uuid' => $uuid, 'iat' => (int) $parts[1], 'exp' => (int) $parts[2], 'sig' => $parts[3]];
    }

    /** Short, human-comparable digest of a public key for display ("ABCD-EFGH-IJKL-MNOP"). */
    public static function fingerprint(string $publicKey): string
    {
        $hash = strtoupper(substr(hash('sha256', self::unb64($publicKey)), 0, 16));

        return implode('-', str_split($hash, 4));
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? '' : $raw;
    }
}
