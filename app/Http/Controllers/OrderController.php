<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Retrieve all orders with donations and their respective item ledger lines.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Retrieve all orders with donations and their item ledger lines
        $orders = Transaction::where('type', 'order')
        ->with(['itemLedgers.item.category','person','status'])
        ->get();
            
            return response()->json($orders);
    }
    
    /**
     * Store a new order.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:order,donation',
            'person_id' => 'nullable|exists:people,id',
            'status_id' => 'required|exists:statuses,id',
            'user_id' => 'required|exists:users,id',
            'order_date' => 'required|date',
            'comments' => 'nullable|string',
            'item_ledgers' => 'nullable|array',
            'item_ledgers.*.item_id' => 'required_with:item_ledgers|exists:items,id',
            'item_ledgers.*.qty_added' => 'nullable|integer',
            'item_ledgers.*.qty_subtracted' => 'nullable|integer',
        ]);
        
        $order = Transaction::create($data);
        
        // Handle related item_ledgers
        if (!empty($data['item_ledgers'])) {
            foreach ($data['item_ledgers'] as $ledger) {
                $order->itemLedgers()->create($ledger);
            }
        }
        
        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order->load('itemLedgers'),
        ], 201);
    }
    
    
    
    /**
     * Update an existing order.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'type' => 'required|in:order,donation',
            'person_id' => 'nullable|exists:people,id',
            'status_id' => 'required|exists:statuses,id',
            'user_id' => 'required|exists:users,id',
            'order_date' => 'required|date',
            'comments' => 'nullable|string',
            'item_ledgers' => 'nullable|array',
            'item_ledgers.*.id' => 'nullable|exists:item_ledgers,id',
            'item_ledgers.*.item_id' => 'required_with:item_ledgers|exists:items,id',
            'item_ledgers.*.qty_added' => 'nullable|integer',
            'item_ledgers.*.qty_subtracted' => 'nullable|integer',
        ]);
        
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
        if (!empty($deletedLedgerIds)) {
            $order->itemLedgers()->whereIn('id', $deletedLedgerIds)->delete();
        }
        
        // Handle updated and new ledgers
        foreach ($data['item_ledgers'] ?? [] as $ledger) {
            if (!empty($ledger['id'])) {
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
            'message' => 'Order updated successfully.',
            'order' => $order->load('itemLedgers'),
        ], 200);
    }
}
    