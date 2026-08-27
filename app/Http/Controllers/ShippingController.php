<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Staff side of Filled -> Ready to Ship -> Shipped -> (review) -> Completed:
 * assign a driver to a Filled order (which also moves it to Ready to Ship),
 * mark it Shipped once the truck actually leaves the dock — a manual,
 * staff-observed event, not something inferred from any other action (per
 * Mark, 2026-08-27: "Shipped is when it leaves the warehouse") — and review
 * a Delivered order's signed BOL (approve() -> Completed, the real
 * terminus, or reject() back to Shipped for the driver to redo). Delivered
 * itself is set by DriverPortalController once the signed BOL comes back,
 * not from here.
 */
class ShippingController extends Controller
{
    private const WITH_RELATIONS = ['person', 'status', 'driver', 'orderLines.itemtype.unit'];

    /**
     * One bucket per status, kept separate rather than merging any of them
     * together — an earlier cut of this page merged Ready to Ship +
     * Shipped into one "assigned" bucket and that was confusing in
     * practice (Mark, 2026-08-27). Filled = waiting for a driver; Ready to
     * Ship = a driver is assigned but hasn't left yet; Shipped = staff
     * confirmed it left the dock; Delivered = the driver's signed BOL is
     * back, awaiting manager review; Completed = approved (a recent window
     * only, like Delivered — otherwise either just grows forever). Mirrors
     * OrderController::index()'s open/recent split for those last two.
     */
    public function index()
    {
        $byStatus = fn (string $status) => Transaction::where('type', 'order')
            ->where('status_id', Transaction::statusId($status))
            ->with(self::WITH_RELATIONS)
            ->orderBy('status_changed_at')
            ->get();

        $recentByStatus = fn (string $status) => Transaction::where('type', 'order')
            ->where('status_id', Transaction::statusId($status))
            ->with(self::WITH_RELATIONS)
            ->orderBy('status_changed_at', 'desc')
            ->limit(25)
            ->get();

        return response()->json([
            'filled' => $byStatus(Transaction::STATUS_FILLED),
            'ready_to_ship' => $byStatus(Transaction::STATUS_READY_TO_SHIP),
            'shipped' => $byStatus(Transaction::STATUS_SHIPPED),
            'delivered' => $byStatus(Transaction::STATUS_DELIVERED),
            'completed' => $recentByStatus(Transaction::STATUS_COMPLETED),
            'drivers' => Driver::orderBy('name')->get(),
        ]);
    }

    /**
     * Assign a driver to a Filled order (moving it to Ready to Ship), or
     * correct the driver on one already Ready to Ship (no status change in
     * that case — it's just fixing who's carrying it).
     */
    public function assign(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $data = $request->validate(['driver_id' => 'required|exists:drivers,id']);

        $statusName = $order->status?->name;
        if ($statusName === Transaction::STATUS_FILLED) {
            $order->update([
                'driver_id' => $data['driver_id'],
                'status_id' => Transaction::statusId(Transaction::STATUS_READY_TO_SHIP),
            ]);
        } elseif ($statusName === Transaction::STATUS_READY_TO_SHIP) {
            $order->update(['driver_id' => $data['driver_id']]);
        } else {
            abort(response()->json([
                'message' => 'Only a Filled or Ready to Ship order can be assigned a driver.',
            ], 409));
        }

        return response()->json(['record' => $order->load(self::WITH_RELATIONS)]);
    }

    /**
     * Ready to Ship -> Shipped, once the load has actually left the dock.
     */
    public function markShipped($id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        if ($order->status?->name !== Transaction::STATUS_READY_TO_SHIP) {
            abort(response()->json([
                'message' => 'Only an order Ready to Ship can be marked Shipped.',
            ], 409));
        }

        $order->update(['status_id' => Transaction::statusId(Transaction::STATUS_SHIPPED)]);

        return response()->json(['record' => $order->load(self::WITH_RELATIONS)]);
    }

    /**
     * Staff download of the driver's returned signed BOL — same
     * gated-download pattern as ReceivingController::photo().
     */
    public function signedBol($id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        abort_unless($order->signed_bol_path, 404);
        abort_unless(Storage::disk('local')->exists($order->signed_bol_path), 404);

        return Storage::disk('local')->response($order->signed_bol_path);
    }

    /**
     * Manager sign-off: the uploaded image is really a signed BOL for this
     * order. An optional replacement file lets the reviewer submit a
     * cropped version (produced client-side — see Shipping.vue's crop
     * tool) instead of the driver's raw upload; omitting it just approves
     * what's already there. Delivered -> Completed, this order type's real
     * terminus.
     */
    public function approve(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        if ($order->status?->name !== Transaction::STATUS_DELIVERED) {
            abort(response()->json([
                'message' => 'Only a Delivered order awaiting review can be approved.',
            ], 409));
        }

        $data = $request->validate(['file' => 'nullable|mimes:jpg,jpeg,png,pdf|max:10240']);

        if ($request->hasFile('file')) {
            if ($order->signed_bol_path) {
                Storage::disk('local')->delete($order->signed_bol_path);
            }
            $order->signed_bol_path = $data['file']->store('signed-bols', 'local');
        }

        $order->bol_rejection_reason = null;
        $order->bol_reviewed_by_person_id = Auth::id();
        $order->status_id = Transaction::statusId(Transaction::STATUS_COMPLETED);
        $order->save();

        return response()->json(['record' => $order->load(self::WITH_RELATIONS)]);
    }

    /**
     * The uploaded image isn't a usable signed BOL (wrong document, no
     * signature, illegible, ...). Back to Shipped — same status the
     * Driver Portal's "current loads" query already includes, so the
     * driver sees this load again with $reason shown on their card and
     * re-uploads through the normal flow, no separate "corrected upload"
     * endpoint needed.
     */
    public function reject(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        if ($order->status?->name !== Transaction::STATUS_DELIVERED) {
            abort(response()->json([
                'message' => 'Only a Delivered order awaiting review can be rejected.',
            ], 409));
        }

        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        if ($order->signed_bol_path) {
            Storage::disk('local')->delete($order->signed_bol_path);
        }

        $order->signed_bol_path = null;
        $order->bol_rejection_reason = $data['reason'] ?? null;
        $order->bol_reviewed_by_person_id = Auth::id();
        $order->status_id = Transaction::statusId(Transaction::STATUS_SHIPPED);
        $order->save();

        return response()->json(['record' => $order->load(self::WITH_RELATIONS)]);
    }
}
