<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\DeviceReleaseResolver;
use App\Services\PrintQueueService;
use Illuminate\Http\Request;

/**
 * POST /api/devices/heartbeat — DEVICE_CONTRACT.md "Heartbeat". Status up,
 * settings and update offers down; every console change reaches the device
 * on its next beat. Extension keys (kiosk_function / kiosk_path) tell the
 * kiosk what to do — see config/devices.php.
 */
class DeviceHeartbeatController extends Controller
{
    public function heartbeat(Request $request, DeviceReleaseResolver $resolver, PrintQueueService $queue)
    {
        $device = $request->attributes->get('managed_device');

        $data = $request->validate([
            'app_version' => 'nullable|string|max:255',
            'os_version' => 'nullable|string|max:255',
            'architecture' => 'nullable|string|max:255',
            'cpu_temp_c' => 'nullable|numeric',
            'hostname' => 'nullable|string|max:255',
            'platform' => 'nullable|in:rstation,windows,macos,linux',
            // Print servers: the OS print queues visible to this device (see PrintQueueService).
            'print_printers' => 'nullable|array|max:100',
            'print_printers.*.queue_name' => 'required|string|max:255',
            'print_printers.*.state' => 'nullable|in:idle,printing,error,offline',
            'print_printers.*.state_message' => 'nullable|string|max:500',
            'language_change' => 'nullable|array',
            'language_change.code' => 'nullable|string|max:10',
            'language_change.base_revision' => 'nullable|integer',
        ]);

        // A missing field leaves the stored value alone rather than blanking it.
        $snapshot = array_filter(
            collect($data)->only(['app_version', 'os_version', 'architecture', 'cpu_temp_c', 'hostname', 'platform'])->all(),
            fn ($value) => $value !== null
        );
        foreach ($snapshot as $column => $value) {
            $device->{$column} = $value;
        }
        $device->last_ip = $request->ip();
        $device->last_seen_at = now();

        $change = $data['language_change'] ?? null;
        if ($change && ! empty($change['code']) && ($change['base_revision'] ?? null) === $device->language_revision) {
            $device->language = $change['code'];
            $device->language_revision++;
        }
        $device->save();

        // Absent key = this device isn't reporting printers (leave the snapshot
        // alone); an empty array is a real report of "no queues".
        if (array_key_exists('print_printers', $data) && $data['print_printers'] !== null) {
            $queue->syncReportedPrinters($device, $data['print_printers']);
        }

        $app = $resolver->offerFor($device, 'app');
        $os = $resolver->offerFor($device, 'os');
        $function = config("devices.functions.{$device->kiosk_function}");

        return response()->json([
            'ok' => true,
            'device_name' => $device->name,
            'entity_name' => $device->location?->name,
            'language' => $device->language,
            'language_revision' => $device->language_revision,
            'latest_app_version' => $app?->version,
            'app_update_available' => $app !== null,
            'app_download_url' => $app?->download_url,
            'app_sha256' => $app?->sha256,
            'latest_os_version' => $os?->version,
            'os_update_available' => $os !== null,
            'os_bundle_url' => $os?->download_url,
            'os_bundle_sha256' => $os?->sha256,
            'os_release_type' => $os?->release_type,
            'os_auto_update_enabled' => $device->os_auto_update_enabled,
            // Extension keys, namespaced per the contract.
            'kiosk_function' => $device->kiosk_function,
            'kiosk_path' => $function['path'] ?? null,
            'kiosk_location_id' => $device->kiosk_location_id,
        ]);
    }
}
