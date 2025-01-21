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
        ]);
        
        $order = Transaction::create($data);
        
        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order,
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
        ]);
        
        $order = Transaction::findOrFail($id);
        $order->update($data);
        
        return response()->json([
            'message' => 'Order updated successfully.',
            'order' => $order,
        ], 200);
    }
    
}

