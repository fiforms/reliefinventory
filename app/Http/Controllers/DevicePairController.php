<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DevicePairingCode;
use App\Models\ManagedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/devices/pair — DEVICE_CONTRACT.md "Pairing". Unauthenticated;
 * the single-use code is the credential. Rate-limited by the route's
 * per-IP throttle plus the slower backoff counter below, since a bare
 * throttle doesn't stop a slow, patient guesser.
 */
class DevicePairController extends Controller
{
    public function pair(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|size:6',
            'device_name' => 'required|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'device_uuid' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
        ]);

        $attemptsKey = 'device-pair-attempts:'.$request->ip();
        if ((int) Cache::get($attemptsKey, 0) > 20) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }
        Cache::add($attemptsKey, 0, now()->addMinutes(10));
        Cache::increment($attemptsKey);

        return DB::transaction(function () use ($data, $request) {
            $code = DevicePairingCode::usable()->where('code', $data['code'])->lockForUpdate()->first();
            if (! $code) {
                // One generic message so existing codes aren't leaked.
                return response()->json(['message' => 'Invalid or expired code.'], 422);
            }

            $uuid = $data['device_uuid'] ?? null;
            // Re-pairing: same physical device re-attaches to its record
            // (new owner/name, revocation cleared, old token replaced).
            $device = $uuid ? ManagedDevice::where('device_uuid', $uuid)->first() : null;
            $device ??= new ManagedDevice(['update_channel' => 'stable']);
            $device->device_uuid = $uuid ?: 'unregistered-'.bin2hex(random_bytes(8));
            $device->fill(['name' => $data['device_name'], 'kiosk_location_id' => $code->kiosk_location_id]);
            $device->mac_address = $data['mac_address'] ?? $device->mac_address;
            if ($device->language === null && ! empty($data['language'])) {
                $device->language = $data['language']; // a hint only; never overrides an admin's choice
                $device->language_revision = 1;
            }
            $device->revoked_at = null;
            $device->paired_at = now();
            $token = $device->issueToken();
            $device->save();

            $code->update(['used_at' => now(), 'managed_device_id' => $device->id]);

            return response()->json([
                'device_id' => $device->id,
                'entity_id' => $device->kiosk_location_id,
                'entity_name' => $device->location?->name,
                'token' => $token,
                'sibling_hostnames' => ManagedDevice::where('kiosk_location_id', $device->kiosk_location_id)
                    ->where('id', '!=', $device->id)->whereNotNull('hostname')->pluck('hostname'),
                'language' => $device->language,
                'language_revision' => $device->language_revision,
            ], 201);
        });
    }
}
