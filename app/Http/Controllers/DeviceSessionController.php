<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DeviceSessionTicket;
use App\Models\ManagedDevice;
use Illuminate\Http\Request;

/**
 * The browser handoff for a paired R Station. The Pi's local backend holds the bearer token; the
 * browser never sees it. Instead the backend mints a single-use ticket (POST /api/devices/
 * session-ticket) and the launcher sends the browser to GET /device-session/{ticket}, which trades
 * it for the `device_session` cookie and redirects to the page for the device's configured
 * Function. The cookie opens only the pages EnsureDeviceAccess allows that Function — it is never a
 * user login.
 */
class DeviceSessionController extends Controller
{
    /** POST /api/devices/session-ticket (device-auth). */
    public function ticket(Request $request)
    {
        return response()->json([
            'ticket' => DeviceSessionTicket::issueFor($request->attributes->get('managed_device')),
            'expires_in' => DeviceSessionTicket::TTL_SECONDS,
        ]);
    }

    /** GET /device-session/{ticket}. An unusable ticket lands on the normal login, never an error page. */
    public function start(Request $request, string $ticket)
    {
        $device = DeviceSessionTicket::redeem($ticket);
        if (! $device || $device->revoked_at) {
            return redirect('/login');
        }

        // The destination is the device's own Function page — never a client-supplied path.
        $path = config("devices.functions.{$device->kiosk_function}.path") ?: '/login';
        $cookie = cookie(
            ManagedDevice::sessionCookie(), $device->issueSessionToken(),
            60 * 24 * 30, '/', null, $request->isSecure(), true, false, 'lax'
        );

        return redirect($path)->withCookie($cookie);
    }
}
