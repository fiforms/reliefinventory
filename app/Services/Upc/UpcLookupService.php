<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Upc;

use App\Models\OfflineModeSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Looks a barcode up in free online product databases (config/upc.php
 * `sources`, tried in order) to *suggest* a description/brand/size. Never
 * throws and never blocks sorting: offline, disabled, throttled or failing
 * services all just mean "no suggestion". Whatever comes back is a suggestion
 * only — a person confirms it before anything is stored (UpcMapping).
 *
 * Statuses: found | none (every source answered, nobody knows it) |
 * unavailable (a source errored/timed out and nothing else found it) |
 * throttled (our own per-minute budget) | disabled (config or Offline Mode).
 * found/none are cached; the rest are not, so they retry next scan.
 */
class UpcLookupService
{
    public function enabled(): bool
    {
        return (bool) config('upc.enabled') && ! OfflineModeSetting::isOffline();
    }

    /**
     * @param  string[]  $codes  14-digit codes to try, best first (e.g. a carton code, then its guessed single-unit code)
     * @return array{status: string, suggestion?: array}
     */
    public function lookup(array $codes): array
    {
        if (! $this->enabled()) {
            return ['status' => 'disabled'];
        }

        $unavailable = false;
        $throttled = false;
        $deadSources = [];

        foreach ($codes as $code) {
            $cacheKey = 'upc-lookup:'.$code;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                if ($cached['status'] === 'found') {
                    return $cached;
                }

                continue; // cached "none" for this code; try the next code
            }

            $sawUnavailable = false;
            foreach (config('upc.sources') as $key => $source) {
                if (isset($deadSources[$key])) {
                    $unavailable = true;

                    continue;
                }
                if (RateLimiter::tooManyAttempts('upc-lookup-budget', (int) config('upc.max_requests_per_minute'))) {
                    $throttled = true;

                    continue;
                }
                RateLimiter::hit('upc-lookup-budget', 60);

                $result = $this->query($key, $source, $code);
                if ($result === false) {
                    $sawUnavailable = $unavailable = true;
                    $deadSources[$key] = true;

                    continue;
                }
                if ($result !== null) {
                    $found = ['status' => 'found', 'suggestion' => $result + ['code' => $code]];
                    Cache::put($cacheKey, $found, now()->addDays((int) config('upc.cache_found_days')));

                    return $found;
                }
            }

            // Only cache a miss when every source actually answered.
            if (! $sawUnavailable && ! $throttled) {
                Cache::put($cacheKey, ['status' => 'none'], now()->addDays((int) config('upc.cache_none_days')));
            }
        }

        return ['status' => $throttled ? 'throttled' : ($unavailable ? 'unavailable' : 'none')];
    }

    /**
     * @return array|null|false suggestion array, null = this source doesn't know it, false = source unavailable
     */
    private function query(string $key, array $source, string $code): array|null|false
    {
        // The services key on the EAN-13 form; drop the GTIN-14 padding zero.
        $query = $code[0] === '0' ? substr($code, 1) : $code;

        try {
            $response = Http::timeout((int) config('upc.timeout'))
                ->withHeaders(['User-Agent' => config('upc.user_agent')])
                ->acceptJson()
                ->get(str_replace('{code}', $query, $source['url']), [
                    'fields' => 'code,product_name,product_name_en,generic_name,brands,quantity,categories,image_front_small_url',
                ]);
        } catch (\Throwable $e) {
            Log::info("UPC lookup via {$key} failed: ".$e->getMessage());

            return false;
        }

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            Log::info("UPC lookup via {$key} returned HTTP ".$response->status());

            return false;
        }
        if ((int) $response->json('status') !== 1) {
            return null;
        }

        return $this->parse($key, $source, (array) $response->json('product'));
    }

    /** Both configured services share Open Food Facts' response shape. */
    private function parse(string $key, array $source, array $product): ?array
    {
        $name = trim((string) ($product['product_name_en'] ?? '')) ?: trim((string) ($product['product_name'] ?? ''))
            ?: trim((string) ($product['generic_name'] ?? ''));
        if ($name === '') {
            return null; // a barcode-only stub is no use as a suggestion
        }

        $brand = trim(explode(',', (string) ($product['brands'] ?? ''))[0]);
        $category = trim(explode(',', (string) ($product['categories'] ?? ''))[0]);

        return [
            'source' => $key,
            'source_name' => $source['name'],
            'description' => $name,
            'brand' => $brand !== '' ? $brand : null,
            'size_text' => trim((string) ($product['quantity'] ?? '')) ?: null,
            'category' => $category !== '' ? $category : null,
            'image_url' => $product['image_front_small_url'] ?? null,
        ];
    }
}
