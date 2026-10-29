<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use App\Services\Federation\RequestSigner;
use RuntimeException;

/**
 * The instance's recovery key pair (MULTI_WAREHOUSE.md §11, scripts/BACKUPS.md "Recovery key"). Whole-instance backups seal every
 * warehouse's key to the PUBLIC half (a libsodium sealed box), so the unattended backup can lock them but nothing on the server can
 * unlock them. The PRIVATE half is shown once, on paper, to the instance administrator, and is needed only for a disaster restore.
 *
 * The private key is meant to be printed and typed back in, so it is not base64: it is 32 random bytes plus a 2-byte checksum in Crockford
 * base32 (no 0/O/1/I confusion; O, I and L are accepted as 0, 1 and 1 when typed), grouped in fives behind a version tag:
 *
 *     RK1-3QX7A-9M2KD-...   (11 groups)
 *
 * A mistyped character fails the checksum before anything is attempted. The public key is base64url, like the federation keys, and is not secret.
 * Its fingerprint (the first 8 bytes of its SHA-256, as `AB12-CD34-EF56-7890`) names which private key a zip needs.
 */
final class RecoveryKey
{
    public const TAG = 'RK1';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const CHECK_CONTEXT = 'ri-recovery-key-v1';

    /** @return array{public: string, private: string, fingerprint: string} */
    public static function generate(): array
    {
        $pair = sodium_crypto_box_keypair();
        $secret = sodium_crypto_box_secretkey($pair);
        $public = RequestSigner::b64(sodium_crypto_box_publickey($pair));
        $private = self::format($secret);
        sodium_memzero($secret);
        sodium_memzero($pair);

        return ['public' => $public, 'private' => $private, 'fingerprint' => self::fingerprint($public)];
    }

    /** Whether $public is a well-formed public key (base64url of 32 bytes). */
    public static function isPublicKey(string $public): bool
    {
        return strlen(RequestSigner::unb64($public)) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES && RequestSigner::b64(RequestSigner::unb64($public)) === $public;
    }

    public static function fingerprint(string $public): string
    {
        return implode('-', str_split(strtoupper(substr(hash('sha256', RequestSigner::unb64($public)), 0, 16)), 4));
    }

    /** The printed form of a secret key. */
    public static function format(string $secret): string
    {
        $groups = str_split(self::encode($secret.self::checksum($secret)), 5);

        return self::TAG.'-'.implode('-', $groups);
    }

    /**
     * The private key in whatever text it was typed, pasted or read from a file (the downloaded file has instructions around the key).
     *
     * @throws RuntimeException with a message for the person typing it
     */
    public static function parse(string $text): string
    {
        if (preg_match('/\bRK1(?:[\s-]*[0-9A-Za-z]{5}){11}\b/', $text, $m)) {
            $text = $m[0];
        }
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $text));
        if (str_starts_with($clean, self::TAG)) {
            $clean = substr($clean, strlen(self::TAG));
        } else {
            throw new RuntimeException('That does not look like a recovery key: it starts with "RK1-".');
        }
        $clean = strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($clean) !== 55 || strspn($clean, self::ALPHABET) !== 55) {
            throw new RuntimeException('That recovery key is the wrong length or has a character that cannot be in one. Check it against the printout.');
        }
        $bytes = self::decode($clean);
        // Canonical only: the last character has 3 spare bits, and a key that differs there is a typing mistake, not another spelling of the same key.
        if (self::encode($bytes) !== $clean) {
            throw new RuntimeException('That recovery key has a typing mistake (its last character is wrong). Check it against the printout.');
        }
        $secret = substr($bytes, 0, 32);
        if (! hash_equals(self::checksum($secret), substr($bytes, 32, 2))) {
            throw new RuntimeException('That recovery key has a typing mistake (its check characters do not match). Check it against the printout.');
        }

        return $secret;
    }

    /** The public key (base64url) belonging to a parsed private key. */
    public static function publicFor(string $secret): string
    {
        return RequestSigner::b64(sodium_crypto_box_publickey_from_secretkey($secret));
    }

    /** Lock $message so only the holder of the matching private key can read it. */
    public static function seal(string $message, string $public): string
    {
        if (! self::isPublicKey($public)) {
            throw new RuntimeException('The recovery public key is not valid.');
        }

        return sodium_crypto_box_seal($message, RequestSigner::unb64($public));
    }

    /** @throws RuntimeException when this private key is not the one the message was sealed to */
    public static function open(string $sealed, string $secret): string
    {
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
        $message = sodium_crypto_box_seal_open($sealed, $pair);
        sodium_memzero($pair);
        if ($message === false) {
            throw new RuntimeException('That recovery key is not the one this backup was sealed to.');
        }

        return $message;
    }

    private static function checksum(string $secret): string
    {
        return substr(hash('sha256', self::CHECK_CONTEXT.$secret, true), 0, 2);
    }

    private static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function decode(string $text): string
    {
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}
