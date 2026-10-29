<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\DeviceReleaseResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/devices/update-check — the release catalogue as a service for *other* Relief
 * Inventory instances' devices. An instance can be pointed at a canonical server for firmware
 * (FirmwareSetting); its devices then ask this endpoint directly with their architecture,
 * installed versions and channel. Unauthenticated, like artifact downloads (devices that
 * aren't paired here have no token), read-only, and answers only from this instance's own
 * releases — it never chains to a source of its own. Same offer fields as the heartbeat.
 */
class DeviceUpdateCheckController extends Controller
{
    public function check(Request $request, DeviceReleaseResolver $resolver)
    {
        $data = $request->validate([
            'architecture' => 'required|string|max:255',
            'app_version' => 'nullable|string|max:255',
            'os_version' => 'nullable|string|max:255',
            'channel' => ['nullable', Rule::in(config('devices.channels'))],
        ]);
        $channel = $data['channel'] ?? 'stable';

        $app = $resolver->offerForState($data['architecture'], $channel, $data['app_version'] ?? null, 'app');
        $os = $resolver->offerForState($data['architecture'], $channel, $data['os_version'] ?? null, 'os');

        return response()->json([
            'latest_app_version' => $app?->version,
            'app_update_available' => $app !== null,
            'app_download_url' => $app?->download_url,
            'app_sha256' => $app?->sha256,
            'latest_os_version' => $os?->version,
            'os_update_available' => $os !== null,
            'os_bundle_url' => $os?->download_url,
            'os_bundle_sha256' => $os?->sha256,
            'os_release_type' => $os?->release_type,
        ]);
    }
}
