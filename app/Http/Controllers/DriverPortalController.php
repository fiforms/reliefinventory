<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\MenuItem;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Driver-facing, unauthenticated (no Person/User account — see Driver's
 * doc comment): a driver signs into this one page with phone + PIN (set by
 * staff via DriverController::setPin) to see their own assigned loads and
 * upload the signed BOL once a delivery is done. The identical page also
 * serves staff holding manage-orders as a read-only view of every
 * Ready to Ship / Shipped order — same URL, audience determined by
 * whether the visitor is logged in as staff or has a driver session (see
 * page()/loads() below), per Mark's 2026-08-27 request. Session key
 * 'driver_id' is intentionally separate from Laravel's Auth guard — a
 * driver is never an authenticated User/Person.
 */
class DriverPortalController extends Controller
{
    private const WITH_RELATIONS = ['person', 'status', 'driver', 'orderLines.itemtype.unit'];

    private function currentDriver(Request $request): ?Driver
    {
        $driverId = $request->session()->get('driver_id');

        return $driverId ? Driver::find($driverId) : null;
    }

    private function isStaffViewer(): bool
    {
        return Auth::check() && Auth::user()->hasPermission('manage-orders');
    }

    public function page(Request $request)
    {
        $driver = $this->currentDriver($request);

        return Inertia::render('DriverPortal', [
            'breadcrumb' => Auth::check() ? MenuItem::getBreadcrumb('/driver-portal') : [],
            'isStaffViewer' => $this->isStaffViewer(),
            'driverName' => $driver?->name,
        ]);
    }

    /**
     * Phone + PIN — looked up by phone since a driver has no username. A
     * shared/mistyped phone number just fails PIN verification for the
     * wrong driver, same failure mode as a wrong PIN. Phone matching is
     * digits-only on both sides — a driver typing "7259198250" must still
     * match a directory entry stored as "725-919-8250" (however staff
     * happened to enter it in DriverController/Receiving.vue).
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string',
            'pin' => 'required|string',
        ]);

        $enteredDigits = preg_replace('/\D+/', '', $data['phone']);
        $driver = $enteredDigits === '' ? null : Driver::whereNotNull('pin_hash')->get()
            ->first(fn (Driver $d) => $d->phone && preg_replace('/\D+/', '', $d->phone) === $enteredDigits
                && $d->verifyPin($data['pin']));

        if (! $driver) {
            return response()->json(['message' => 'Phone number or PIN not recognized.'], 422);
        }

        $request->session()->put('driver_id', $driver->id);

        return response()->json(['driverName' => $driver->name]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('driver_id');

        return response()->json(['success' => true]);
    }

    /**
     * The driver's own assigned loads (Ready to Ship / Shipped, plus a
     * short recent-Delivered history for confirmation), or — for a staff
     * viewer with manage-orders and no driver session — every order in
     * those same two statuses across all drivers.
     */
    public function loads(Request $request)
    {
        $driver = $this->currentDriver($request);

        if (! $driver && ! $this->isStaffViewer()) {
            return response()->json(['message' => 'Not signed in.'], 401);
        }

        $currentStatuses = [
            Transaction::statusId(Transaction::STATUS_READY_TO_SHIP),
            Transaction::statusId(Transaction::STATUS_SHIPPED),
        ];

        $currentQuery = Transaction::where('type', 'order')
            ->whereIn('status_id', $currentStatuses)
            ->with(self::WITH_RELATIONS);

        $deliveredQuery = Transaction::where('type', 'order')
            ->where('status_id', Transaction::statusId(Transaction::STATUS_DELIVERED))
            ->with(self::WITH_RELATIONS)
            ->orderBy('status_changed_at', 'desc')
            ->limit(10);

        if ($driver) {
            $currentQuery->where('driver_id', $driver->id);
            $deliveredQuery->where('driver_id', $driver->id);
        }

        return response()->json([
            'current' => $currentQuery->orderBy('status_changed_at')->get(),
            'delivered' => $deliveredQuery->get(),
        ]);
    }

    /**
     * Upload the signed/scanned BOL for one load — the driver assigned to
     * it, or staff (manage-orders) uploading on the driver's behalf (e.g. a
     * paper copy handed in at the dock). Moves the order straight to
     * Delivered: per Mark, "once the signed BOL is uploaded we are done
     * with that order."
     */
    public function uploadBol(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $driver = $this->currentDriver($request);
        $staff = $this->isStaffViewer();

        if (! $staff && (! $driver || (int) $order->driver_id !== $driver->id)) {
            abort(403, 'This load is not assigned to you.');
        }

        if (! in_array($order->status?->name, [Transaction::STATUS_READY_TO_SHIP, Transaction::STATUS_SHIPPED], true)) {
            abort(response()->json([
                'message' => 'This load is not currently awaiting a signed BOL.',
            ], 409));
        }

        $request->validate(['file' => 'required|mimes:jpg,jpeg,png,pdf|max:10240']);

        if ($order->signed_bol_path) {
            Storage::disk('local')->delete($order->signed_bol_path);
        }

        $order->signed_bol_path = $request->file('file')->store('signed-bols', 'local');
        $order->bol_rejection_reason = null; // clear any note from a prior rejected attempt
        $order->status_id = Transaction::statusId(Transaction::STATUS_DELIVERED);
        $order->save();

        return response()->json(['record' => $order->load(self::WITH_RELATIONS)]);
    }
}
