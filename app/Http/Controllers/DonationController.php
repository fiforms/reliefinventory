<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DonationController extends Controller
{
    private const validation = [
        'type' => 'required|in:donation',
        'person_id_user' => 'required|exists:people,id',
        'person_id' => 'nullable|exists:people,id',
        'status_id' => 'required|exists:statuses,id',
        'order_date' => 'required|date',
        'comments' => 'nullable|string',
        'item_ledgers' => 'nullable|array',
        'item_ledgers.*.item_id' => 'required_with:item_ledgers|exists:items,id',
        'item_ledgers.*.qty_added' => 'nullable|integer',
        'item_ledgers.*.qty_subtracted' => 'nullable|integer',
    ];

    /**
     * Retrieve all orders with donations and their respective item ledger lines.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        // Newest first and capped (default 100, ?limit= up to 500): each donation carries all of its ledger
        // lines, so "all of them" is megabytes. Nothing in the UI uses this list now (Receiving and Sorting
        // have their own bounded ones).
        $orders = Transaction::where('type', 'donation')
            ->with(['itemLedgers.item.itemtype', 'enteredBy'])
            ->orderByDesc('id')
            ->limit(min(max((int) $request->query('limit', 100), 1), 500))
            ->get();

        return response()->json([
            'records' => $orders,
            'templates' => [
                '_default' => [
                    'type' => 'donation',
                    'person_id_user' => Auth::id(),
                    'person_id' => null,
                    'status_id' => 4,
                    'order_date' => date('Y-m-d'),
                    'comments' => null,
                    'item_ledgers' => [],
                ],
                'item_ledgers' => [
                    'item_id' => null,
                    'qty_subtracted' => null,
                    'item' => ['description' => null],
                ],
            ],
        ]);
    }

    /**
     * Store a new order.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        $order = Transaction::create($data);

        // Handle related item_ledgers
        if (! empty($data['item_ledgers'])) {
            foreach ($data['item_ledgers'] as $ledger) {
                $order->itemLedgers()->create($ledger);
            }
        }

        return response()->json([
            'message' => 'Donation created successfully.',
        ], 201);
    }

    /**
     * Update an existing order.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate(self::validation);

        $order = Transaction::findOrFail($id);
        $order->update($data);

        // Retrieve current item_ledgers IDs from the database
        $existingLedgerIds = $order->itemLedgers->pluck('id')->toArray();

        // Extract IDs from the incoming request
        $updatedLedgerIds = collect($data['item_ledgers'] ?? [])
            ->pluck('id')
            ->filter() // Remove nulls (new records won't have IDs)
            ->toArray();

        // Find IDs that need to be deleted
        $deletedLedgerIds = array_diff($existingLedgerIds, $updatedLedgerIds);

        // Delete removed ledgers
        if (! empty($deletedLedgerIds)) {
            $order->itemLedgers()->whereIn('id', $deletedLedgerIds)->delete();
        }

        // Handle updated and new ledgers
        foreach ($data['item_ledgers'] ?? [] as $ledger) {
            if (! empty($ledger['id'])) {
                // Update existing ledger
                $existingLedger = $order->itemLedgers()->find($ledger['id']);
                if ($existingLedger) {
                    $existingLedger->update($ledger);
                }
            } else {
                // Create new ledger
                $order->itemLedgers()->create($ledger);
            }
        }

        return response()->json([
            'message' => 'Donation updated successfully.',
        ], 200);
    }

    public function destroy($id)
    {
        try {
            $order = Transaction::findOrFail($id);
            $order->delete();

            return response()->json([
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting order item: '.$e->getMessage(),
            ], 500);
        }
    }
}
