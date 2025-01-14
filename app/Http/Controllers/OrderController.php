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
            ->with(['itemLedgers.item.category'])
            ->get();
            
            return response()->json($orders);
    }
}
