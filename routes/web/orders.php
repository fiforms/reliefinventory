<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Orders, filling and shipping
 *
 * Order Entry, Order Filling and Shipping: the pages, their /json endpoints, the driver PIN, and the order documents (order form, pick
 * sheets, bill of lading, shipping label). See dev/notes/ORDERS_AND_FILLING.md.
 *
 * Access: `permission:manage-orders` (resource-level, not row-level). The driver portal, which is public, is in web/public.php.
 *
 * Loaded from routes/web.php. Read dev/notes/ROUTES.md before adding or moving a route.
 */

// Data behind this page (orders/people/items) all require manage-orders
// or above — the page itself used to be auth-only, letting a
// permission-less user load a page whose data would just fail to load.
Route::get('/order-entry', function () {
    return Inertia::render('OrderEntry',
        ['breadcrumb' => MenuItem::getBreadcrumb('/order-entry')]);
})->middleware(['auth', 'permission:manage-orders']);

Route::get('/order-filling', function () {
    return Inertia::render('OrderFilling',
        ['breadcrumb' => MenuItem::getBreadcrumb('/order-filling')]);
})->middleware(['auth', 'permission:manage-orders']);

Route::get('/shipping', function () {
    return Inertia::render('Shipping',
        ['breadcrumb' => MenuItem::getBreadcrumb('/shipping')]);
})->middleware(['auth', 'permission:manage-orders']);

Route::group(['prefix' => 'json', 'middleware' => ['auth', 'permission:manage-orders']], function () {
    // Order intake sessions (header created at partner confirm; requested
    // lines autosave one at a time — see OrderController). Routes/permission
    // key/DB stay "order" — partner-facing UI shows "Request" instead, see
    // OrderController's doc comment.
    Route::get('/orders', [OrderController::class, 'index']);
    // before /orders/{id} so "stock-hints" isn't captured as an id
    Route::get('/orders/stock-hints', [OrderController::class, 'stockHints']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}', [OrderController::class, 'update']);
    Route::patch('/orders/{id}/complete', [OrderController::class, 'complete']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
    Route::post('/orders/{id}/lines', [OrderController::class, 'storeLine']);
    Route::put('/orders/{id}/lines/{lineId}', [OrderController::class, 'updateLine']);
    Route::delete('/orders/{id}/lines/{lineId}', [OrderController::class, 'destroyLine']);

    // Order Filling/Picking — the order (Transaction) itself is the filling
    // session, same as donation sorting; see OrderFillingController's doc
    // comment. Fill records are ItemLedger rows nested under an order line.
    Route::get('/order-filling', [OrderFillingController::class, 'index']);
    Route::patch('/order-filling/{id}/start', [OrderFillingController::class, 'start']);
    Route::post('/order-filling/print-pick-sheets', [OrderFillingController::class, 'printPickSheets']);
    Route::put('/order-filling/allocations', [OrderFillingController::class, 'approveAllocation']);
    Route::patch('/order-filling/{id}/complete', [OrderFillingController::class, 'completeFilling']);
    Route::get('/order-filling/{id}/plan', [OrderFillingController::class, 'plan'])->whereNumber('id');
    Route::post('/order-filling/pallets/{id}/open', [OrderFillingController::class, 'openPallet'])->whereNumber('id');
    Route::post('/order-filling/{id}/lines/{lineId}/fills', [OrderFillingController::class, 'storeFill']);
    Route::put('/order-filling/{id}/lines/{lineId}/fills/{fillId}', [OrderFillingController::class, 'updateFill']);
    Route::delete('/order-filling/{id}/lines/{lineId}/fills/{fillId}', [OrderFillingController::class, 'destroyFill']);

    // Shipping — Filled -> Ready to Ship (driver assigned) -> Shipped
    // (staff-confirmed departure); see ShippingController's doc comment.
    // Delivered is set separately, by DriverPortalController.
    Route::get('/shipping', [ShippingController::class, 'index']);
    Route::patch('/shipping/{id}/assign', [ShippingController::class, 'assign']);
    Route::patch('/shipping/{id}/mark-shipped', [ShippingController::class, 'markShipped']);
    Route::get('/shipping/{id}/signed-bol', [ShippingController::class, 'signedBol']);
    Route::post('/shipping/{id}/approve', [ShippingController::class, 'approve']);
    Route::post('/shipping/{id}/reject', [ShippingController::class, 'reject']);

    // Driver Portal PIN — how a driver gets into /driver-portal. Kept under
    // manage-orders (Shipping's own permission) rather than manage-receiving
    // like the rest of DriverController, since this is a shipping concern.
    Route::post('/drivers/{driver}/set-pin', [DriverController::class, 'setPin']);

    // Dead code (no consuming Vue page — SortingSessionController owns
    // donation creation now), left routed rather than silently
    // orphaned; shares orders' permission since it's the same table.
    Route::get('/donations', [DonationController::class, 'index']);
    Route::post('/donations', [DonationController::class, 'store']);
    Route::put('/donations/{id}', [DonationController::class, 'update']);
    Route::delete('/donations/{id}', [DonationController::class, 'destroy']);
});

// Offline order form (in-stock item types only, no quantities) — printed or
// emailed to a POD/partner, then hand-keyed back in as an order.
Route::get('/report/order-form.pdf', [OrderController::class, 'orderFormPdf'])
    ->name('report.order-form')
    ->middleware(['auth', 'permission:manage-orders']);

// Pure render, no mutation (see OrderFillingController::printPickSheets for
// the batch select+lock step that precedes this) — safe to reload/reprint.
Route::get('/report/pick-sheets.pdf', [OrderFillingController::class, 'pickSheetsPdf'])
    ->name('report.pick-sheets')
    ->middleware(['auth', 'permission:manage-orders']);

// BOL (Bill of Lading) for a Filled order. bol_number is assigned on first
// generation and reused thereafter — safe to reload/reprint.
Route::get('/report/bol/{id}.pdf', [OrderController::class, 'bolPdf'])
    ->name('report.bol')
    ->middleware(['auth', 'permission:manage-orders']);

// 4 x 6.5 in shipping label for a Filled order (pure render, no mutation).
Route::get('/report/shipping-label/{id}.pdf', [OrderController::class, 'shippingLabelPdf'])
    ->name('report.shipping-label')
    ->middleware(['auth', 'permission:manage-orders']);
