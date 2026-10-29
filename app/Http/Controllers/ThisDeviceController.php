<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\DeviceApprovalRequested;
use App\Services\PinLoginService;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * "PIN Login on This Device" (profile menu, any signed-in user): the only way a device gets recorded. Someone with
 * manage-trusted-devices approves the device they're on at once; anyone else files a request — an optional station
 * name and reason — which shows on Setup > PIN Login (and in the approvers' notification bell) to approve or deny.
 * The request keeps what it came from: IP address, browser and OS (App\Support\UserAgent).
 */
class ThisDeviceController extends Controller
{
    public function show(Request $request, PinLoginService $pinLogin)
    {
        return $this->payload($request, $pinLogin, $pinLogin->deviceFromCookie($request));
    }

    /** $device is passed in: right after a first request its cookie is only queued, not readable from $request. */
    private function payload(Request $request, PinLoginService $pinLogin, ?TrustedDevice $device)
    {
        $device?->load(['requester', 'approver']);

        return response()->json([
            'device' => $device ? [
                'status' => $device->status,
                'label' => $device->label,
                'request_reason' => $device->request_reason,
                'requested_at' => $device->requested_at,
                'requester' => $device->requester?->full_name,
                'approved_at' => $device->approved_at,
                'approver' => $device->approver?->full_name,
                'kiosk_mode' => $device->kiosk_mode,
            ] : null,
            // What a request from here would record.
            'detected' => ['ip_address' => $request->ip(), ...UserAgent::parse($request->userAgent())],
            'can_approve' => Auth::user()->hasPermission('manage-trusted-devices'),
            'pin_login_enabled' => $pinLogin->settings()->enabled,
            'has_pin' => Auth::user()->hasPin(),
        ]);
    }

    public function store(Request $request, PinLoginService $pinLogin)
    {
        $data = $request->validate([
            'label' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
        ]);
        $existing = $pinLogin->deviceFromCookie($request);
        if ($existing?->isApproved()) {
            return response()->json(['message' => 'This device is already approved for PIN login.'], 409);
        }

        $user = Auth::user();
        $attributes = [
            'label' => $data['label'] ?? $existing?->label,
            'request_reason' => $data['reason'] ?? null,
            'requested_by_person_id' => $user->id,
            'requested_at' => now(),
        ];

        if ($user->hasPermission('manage-trusted-devices')) {
            $device = $pinLogin->registerThisDevice($request, $attributes + [
                'status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(),
            ]);
            // They're already logged in here: their own PIN tile shows on this device from now on.
            $pinLogin->grantTrust($device, $user->id);
        } else {
            $device = $pinLogin->registerThisDevice($request, $attributes + [
                'status' => 'pending', 'approved_by' => null, 'approved_at' => null,
            ]);
            // Updating a request that's already waiting doesn't ring the bell again.
            if ($existing?->status !== 'pending') {
                $approvers = User::withPermission('manage-trusted-devices')->whereNull('disabled_at')->get();
                Notification::send($approvers, new DeviceApprovalRequested($device));
            }
        }

        return $this->payload($request, $pinLogin, $device);
    }
}
