<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class MenuController extends Controller
{
    /**
     * Fetch all pages and their associated menu items as JSON.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $user_level = $user->role_bitpack; 
        
        // Fetch all pages with their associated menu items filtered by user role level
        $pages = Page::with(['menuItems' => function ($query) use ($user_level) {
            $query->where('role_level', '<=', $user_level)
            ->orderBy('order');
        }])->get();
        
        // Return the data as a JSON response
        return response()->json($pages);
    }
}
