<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DevicePairingCode;
use App\Models\ManagedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin side of the kiosk fleet (manage-devices): list, edit, revoke and
 * remove devices, and generate pairing codes. A device finds out about a
 * rename/channel/function change on its next heartbeat (≤5 min) and about
 * revocation as a 401 on it.
 */
class ManagedDeviceController extends Controller
{
    public function index()
    {
        $devices = ManagedDevice::with(['location:id,name', 'printers'])->orderBy('name')->get();
        // Printer status is derived from its device; hand it the one we already loaded.
        $devices->each(fn ($device) => $device->printers->each->setRelation('device', $device));

        return response()->json([
            'records' => $devices,
            'functions' => collect(config('devices.functions'))->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label']])->values(),
            'channels' => config('devices.channels'),
            'media' => collect(config('printing.media'))->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label']])->values(),
        ]);
    }

    public function update(Request $request, ManagedDevice $managedDevice)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'kiosk_location_id' => 'nullable|exists:kiosk_locations,id',
            'update_channel' => ['required', Rule::in(config('devices.channels'))],
            'os_auto_update_enabled' => 'boolean',
            'kiosk_function' => ['nullable', Rule::in(array_keys(config('devices.functions')))],
            'language' => 'nullable|string|max:10',
        ]);

        $managedDevice->fill($data);
        if ($managedDevice->isDirty('language')) {
            $managedDevice->language_revision++;
        }
        $managedDevice->save();

        return response()->json(['message' => 'Device updated.', 'record' => $managedDevice->load('location:id,name')]);
    }

    /** Un-pair: the device learns at its next heartbeat and returns to setup. */
    public function revoke(ManagedDevice $managedDevice)
    {
        $managedDevice->update(['revoked_at' => now()]);
        $managedDevice->token_hash = null;
        $managedDevice->save();

        return response()->json(['message' => 'Device revoked.', 'record' => $managedDevice]);
    }

    public function destroy(ManagedDevice $managedDevice)
    {
        $managedDevice->delete();

        return response()->json(['message' => 'Device removed.']);
    }

    public function createPairingCode(Request $request)
    {
        $data = $request->validate(['kiosk_location_id' => 'required|exists:kiosk_locations,id']);

        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (DevicePairingCode::where('code', $code)->exists());

        $record = DevicePairingCode::create([
            'code' => $code,
            'kiosk_location_id' => $data['kiosk_location_id'],
            'created_by' => Auth::id(),
            'expires_at' => now()->addMinutes(config('devices.pairing_code_minutes')),
        ]);

        return response()->json(['record' => $record], 201);
    }
}
