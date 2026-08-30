<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\OfflineModeSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around geocod.io's forward-geocode endpoint, used to
 * *suggest* a county from an entered street address — the actual county a
 * street address falls in is unambiguous (unlike a ZIP code, which can
 * legitimately span several counties), so this is a stronger signal than a
 * ZIP crosswalk. Free tier only (2,500 lookups/day); no paid ZIP+4/
 * deliverability append is used or needed here — see
 * GeocodeController::lookupCounty.
 */
class GeocodioService
{
    private const ENDPOINT = 'https://api.geocod.io/v2/geocode';

    public function enabled(): bool
    {
        return (bool) config('services.geocodio.key') && ! OfflineModeSetting::isOffline();
    }

    /**
     * Look up an address and return the top result's components, or null
     * if the feature is disabled, the request fails, or nothing matched.
     * Never throws — this is an optional convenience, not something that
     * should block a save if geocod.io is unreachable.
     */
    public function lookup(array $address): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $street = trim($address['address'] ?? '');
        if ($street === '') {
            return null;
        }

        try {
            $response = Http::timeout(5)->get(self::ENDPOINT, [
                'street' => $street,
                'city' => $address['city'] ?? null,
                'state' => $address['state'] ?? null,
                'postal_code' => $address['zip'] ?? null,
                'api_key' => config('services.geocodio.key'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Geocodio request failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Geocodio request failed with status '.$response->status());

            return null;
        }

        $result = $response->json('results.0');
        if (! $result) {
            return null;
        }

        $components = $result['address_components'] ?? [];

        return [
            'formatted_address' => $result['formatted_address'] ?? null,
            'accuracy' => $result['accuracy'] ?? null,
            'accuracy_type' => $result['accuracy_type'] ?? null,
            // address_lines[0] is geocod.io's standardized street line
            // (number + parsed/corrected street name+suffix) — used to
            // offer a corrected address, never to silently replace what
            // was typed. See GeocodeController::lookupCounty.
            'address' => $result['address_lines'][0] ?? null,
            'city' => $components['city'] ?? null,
            // Geocodio's actual field names, confirmed against a live
            // request — the "state"/"zip" names shown in the public docs
            // excerpt were wrong.
            'state' => $components['state_province'] ?? null,
            'zip' => $components['postal_code'] ?? null,
            'county' => $components['county'] ?? null,
        ];
    }
}
