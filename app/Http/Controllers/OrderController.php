<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    
    private const validation = [
        'type' => 'required|in:order',
        'person_id_user' => 'required|exists:people,id',
        'person_id' => 'nullable|exists:people,id',
        'status_id' => 'required|exists:statuses,id',
        'order_date' => 'required|date',
        'comments' => 'nullable|string',
        'item_ledgers' => 'nullable|array',
        'item_ledgers.*.item_id' => 'required_with:item_ledgers|exists:items,id',
        'item_ledgers.*.qty_added' => 'nullable|integer',
        'item_ledgers.*.qty_subtracted' => 'nullable|integer',
        'order_lines' => 'nullable|array',
        'order_lines.*.itemtype_id' => 'required_with:order_lines|exists:itemtypes,id',
        'order_lines.*.packagetype_id' => 'required_with:order_lines|exists:packagetypes,id',
        'order_lines.*.qty_requested' => 'required_with:order_lines|integer|min:1',
        'order_lines.*.comments' => 'nullable|string',
    ];
    
    /**
     * Retrieve all orders with donations and their respective item ledger lines.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Retrieve all orders with donations and their item ledger lines
        $orders = Transaction::where('type', 'order')
        ->with(['OrderLines.itemtype','OrderLines.packagetype','itemLedgers.item.itemtype','person','status'])
        ->get();
            
            return response()->json([
                'records' => $orders,
                'templates' => [
                    '_default' => [
                        'type' => 'order',
                        'person_id_user' => Auth::id(),
                        'person_id' => null,
                        'person' => [],
                        'status_id' => null,
                        'order_date' => date('Y-m-d'),
                        'comments' => null,
                        'item_ledgers' => [],
                        'order_lines' => [],
                   ],
                   'item_ledgers' => [
                        'item_id' => null,
                        'qty_subtracted' => null,
                        'item' => ['description' => null],
                   ],
                   'order_lines' => [
                        'itemtype_id' => null,
                        'packagetype_id' => null,
                        'qty_requested' => null,
                        'comments' => null,
                   ],
                ]
              ]);
    }
     
    /**
     * Store a new order.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        $order = Transaction::create($data);
        
        // Handle related item_ledgers
        if (!empty($data['item_ledgers'])) {
            foreach ($data['item_ledgers'] as $ledger) {
                $order->itemLedgers()->create($ledger);
            }
        }
        
        if (!empty($data['order_lines'])) {
            foreach ($data['order_lines'] as $line) {
                $order->orderLines()->create($line);
            }
        }
        
        return response()->json([
            'message' => 'Order created successfully.',
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
        $data = $request->validate(self::validation);
        
        $order = Transaction::findOrFail($id);
        
        // Ensure person_id_user is never updated
        unset($data['person_id_user']);

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
        
        // Similar algorithm for OrderLines
        $existingLineIds = $order->orderLines->pluck('id')->toArray();
        $updatedLineIds = collect($data['order_lines'] ?? [])->pluck('id')->filter()->toArray();
        $deletedLineIds = array_diff($existingLineIds, $updatedLineIds);
        if (!empty($deletedLineIds)) {
            $order->orderLines()->whereIn('id', $deletedLineIds)->delete();
        }
        foreach ($data['order_lines'] ?? [] as $line) {
            if (!empty($line['id'])) {
                $order->orderLines()->find($line['id'])?->update($line);
            } else {
                $order->orderLines()->create($line);
            }
        }
        
        
        return response()->json([
            'message' => 'Order updated successfully.',
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
                'message' => 'Error deleting order item: ' . $e->getMessage()
            ], 500);
        }
    }
}
    