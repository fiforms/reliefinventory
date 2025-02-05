<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Status;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    /**
     * Display a listing of the statuses.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $statuses = Status::all(); // Fetch all statuses
        return response()->json(['records' => $statuses]);
    }
}

