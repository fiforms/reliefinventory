<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Federation;

use App\Models\Trust;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outbound calls from this instance to a trusted peer instance, signed with this instance's key. After the
 * first signed call it trades up to a session token (cached per trust) so later requests need no signature;
 * a 401 on a session drops it and goes back to signing once. Never follows redirects, and the peer's address
 * is re-checked by PeerUrl on every call.
 */
class FederationClient
{
    private const PREFIX = '/api/federation/v1';

    public function __construct(private Trust $trust) {}

    public static function for(Trust $trust): self
    {
        return new self($trust);
    }

    public function get(string $path): Response
    {
        return $this->send('GET', $path, '');
    }

    public function post(string $path, array $data = []): Response
    {
        return $this->send('POST', $path, json_encode((object) $data));
    }

    private function send(string $method, string $path, string $body): Response
    {
        $origin = PeerUrl::assertSafe((string) $this->trust->base_url);
        $path = self::PREFIX.$path;

        $session = $this->session($origin);
        if ($session) {
            $response = $this->dispatch($origin, $method, $path, $body, ['Authorization' => 'Bearer '.$session]);
            if ($response->status() !== 401) {
                return $response;
            }
            Cache::forget($this->sessionKey());
        }

        return $this->dispatch($origin, $method, $path, $body, $this->signedHeaders($origin, $method, $path, $body));
    }

    private function session(string $origin): ?string
    {
        $cached = Cache::get($this->sessionKey());
        if ($cached) {
            return $cached;
        }
        $path = self::PREFIX.'/session';
        $response = $this->dispatch($origin, 'POST', $path, '{}', $this->signedHeaders($origin, 'POST', $path, '{}'));
        if (! $response->successful() || ! $response->json('session_token')) {
            return null; // fall back to signing the real request, which will surface the real error
        }
        Cache::put($this->sessionKey(), $response->json('session_token'), max(60, (int) $response->json('expires_in') - 60));

        return $response->json('session_token');
    }

    private function signedHeaders(string $origin, string $method, string $path, string $body): array
    {
        $timestamp = time();
        $nonce = Str::random(24);
        $canonical = RequestSigner::canonical($method, $path, PeerUrl::hostHeader($origin), $timestamp, $nonce, $body);

        return [
            'X-RI-Trust' => InstanceIdentity::current()->uuid,
            'X-RI-Timestamp' => (string) $timestamp,
            'X-RI-Nonce' => $nonce,
            'X-RI-Signature' => InstanceIdentity::sign($canonical),
        ];
    }

    private function dispatch(string $origin, string $method, string $path, string $body, array $headers): Response
    {
        $request = Http::timeout(10)->withoutRedirecting()->acceptJson()->withHeaders($headers);
        if ($method === 'POST') {
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, $origin.$path);
    }

    private function sessionKey(): string
    {
        return 'fed-out-session:'.$this->trust->id;
    }
}
