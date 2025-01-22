<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;

class ItemController extends Controller
{
    /**
     * Get all items as JSON.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $items = Item::all()->map(function ($item) {
           $item->name = $item->item_number.' '.$item->description;
           return $item;
        });
        
          return response()->json(['records' => $items,
              'templates' => [
                  '_default' => [
                      'item_number' => null,
                      'category_id' => null,
                      'counted_by' => null,
                      'size' => null,
                      'case_qty' => null,
                      'active' => null,
                      'description' => null,
                    ]
                  ]
              ]);
    }
}
