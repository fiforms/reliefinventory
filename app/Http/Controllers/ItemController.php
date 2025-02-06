<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Item;

class ItemController extends Controller
{
    
    
    // Display a listing of items
    public function index()
    {
        $items = Item::all()->map(function ($item) {
            $item->name = $item->item_number.' '.$item->description;
            return $item;
        });
        $templates = ['_default' => []];

        return response()->json([
            'records' => $items,
            'templates' => $templates
        ]);
    }

}
