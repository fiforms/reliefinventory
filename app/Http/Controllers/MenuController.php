<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class MenuController extends Controller
{
    /**
     * Fetch all pages and their associated menu items as JSON.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        // Fetch all pages with their associated menu items, then drop any
        // item the user's effective permissions don't cover. A null
        // permission_key means "visible to everyone authenticated".
        $pages = Page::with(['menuItems' => function ($query) {
            $query->orderBy('order');
        }])->get();

        $pages->each(function ($page) use ($user) {
            $page->setRelation('menuItems', $page->menuItems->filter(
                fn ($item) => ! $item->permission_key || $user->hasPermission($item->permission_key)
            )->values());
        });

        // Return the data as a JSON response
        return response()->json($pages);
    }
}
