<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1), same shape as PinLoginSetting/BannerSetting —
 * admin-editable replacement for the FEEDBACK_INSTANCE_ROLE /
 * FEEDBACK_INSTANCE_IDENTIFIER / FEEDBACK_CENTRAL_INGEST_URL /
 * FEEDBACK_SHARED_SECRET env vars. `shared_secret` uses Eloquent's
 * `encrypted` cast (app key-derived, not plaintext at rest) and is
 * transparently decrypted on read/encrypted on write by the cast itself.
 */
class FeedbackAggregationSetting extends Model
{
    protected $table = 'feedback_aggregation_settings';

    /** Where FeedbackIngestController listens on every instance. */
    public const INGEST_PATH = '/json/feedback/ingest';

    /** Prefix of a connection code (see connectionCode()); the digit is the format version. */
    public const CODE_PREFIX = 'RIFEED1-';

    protected $fillable = [
        'instance_role',
        'instance_identifier',
        'central_ingest_url',
        'shared_secret',
    ];

    protected $casts = [
        'shared_secret' => 'encrypted',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /**
     * Whether central push is configured at all — mirrors the old
     * config()-based check in FeedbackPushService.
     */
    public function pushConfigured(): bool
    {
        return $this->instance_role === 'remote'
            && $this->ingestUrl() !== null
            && filled($this->shared_secret);
    }

    /**
     * The address pushes actually go to — the stored value normalized, so a
     * row saved before normalization existed (e.g. just
     * "https://demo.reliefinventory.app") still works.
     */
    public function ingestUrl(): ?string
    {
        return self::normalizeCentralUrl($this->central_ingest_url);
    }

    /**
     * Turns whatever an admin typed for the central instance — a bare domain
     * ("demo.reliefinventory.app"), the site address, or the full ingest URL —
     * into the ingest URL. https:// is assumed when no scheme is given (an
     * http:// address that redirects to https turns the POST into a GET, so
     * http only works where it is really served). Any path other than the
     * ingest path is dropped, so a copied page address works too. Returns
     * null for blank or unusable input.
     */
    public static function normalizeCentralUrl(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host)) {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if (! str_ends_with($path, self::INGEST_PATH)) {
            $path = self::INGEST_PATH;
        }

        return $scheme.'://'.strtolower($host).(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }

    /**
     * A single pasteable string holding a central instance's ingest URL and
     * shared secret, so a remote instance is set up by pasting one code. It
     * carries the secret, so it is shown only on request and should be
     * handled like a password.
     */
    public static function connectionCode(string $ingestUrl, string $secret): string
    {
        $json = json_encode(['url' => $ingestUrl, 'secret' => $secret], JSON_UNESCAPED_SLASHES);

        return self::CODE_PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{url: string, secret: string}|null null if it isn't a valid code
     */
    public static function parseConnectionCode(string $code): ?array
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! str_starts_with($code, self::CODE_PREFIX)) {
            return null;
        }

        $json = base64_decode(strtr(substr($code, strlen(self::CODE_PREFIX)), '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || ! is_string($data['url'] ?? null) || ! is_string($data['secret'] ?? null) || $data['secret'] === '') {
            return null;
        }

        $url = self::normalizeCentralUrl($data['url']);

        return $url === null ? null : ['url' => $url, 'secret' => $data['secret']];
    }

    /**
     * Defaults to this instance's own APP_URL host when left blank — see
     * FeedbackPushService::instanceIdentifier(), which delegates here.
     */
    public function resolvedIdentifier(): string
    {
        if (filled($this->instance_identifier)) {
            return $this->instance_identifier;
        }

        return parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url');
    }
}
