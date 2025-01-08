<?php

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
        return response()->json($statuses, 200);
    }
}

