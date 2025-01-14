<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    /**
     * Fetch all pages and their associated menu items as JSON.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Fetch all pages with their associated menu items
        $pages = Page::with('menuItems')->get();

        // Return the data as a JSON response
        return response()->json($pages);
    }
}
