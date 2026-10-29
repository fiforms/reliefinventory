<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Services\Simulator\SimulationRun;
use App\Services\Simulator\WarehouseSimulator;
use App\Services\SlowQueryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup > Testing Tools (admin-system): the warehouse simulator (progress, and
 * a start button while the instance is still empty) and the slow-query log.
 * Both are for finding formatting and performance problems before real data
 * exists; neither is needed in normal operation.
 */
class TestingToolsController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('TestingTools', [
            'breadcrumb' => MenuItem::getBreadcrumb('/setup/testing'),
        ]);
    }

    public function status(SimulationRun $run, SlowQueryLog $slow): JsonResponse
    {
        $blocked = WarehouseSimulator::blockedReason() ?? WarehouseSimulator::missingRequirement();
        $simulation = $run->status();

        return response()->json([
            'simulation' => $simulation,
            // Offered only when nothing is running and the instance is still empty.
            'can_start' => $blocked === null && ($simulation['state'] ?? 'none') !== 'running',
            'blocked_reason' => ($simulation['state'] ?? 'none') === 'running' ? null : $blocked,
            'slow_log' => [
                'enabled' => $slow->enabled(),
                'threshold_ms' => $slow->thresholdMs(),
                'request_ms' => (int) config('slowlog.request_ms'),
                'request_queries' => (int) config('slowlog.request_queries'),
                'request_bytes' => (int) config('slowlog.request_bytes'),
            ],
        ]);
    }

    public function startSimulation(Request $request, SimulationRun $run): JsonResponse
    {
        $data = $request->validate([
            'days' => 'required|integer|min:7|max:1500',
            'orders' => 'required|integer|min:1|max:20000',
            'errors' => 'required|integer|min:0|max:50',
            'seed' => 'nullable|integer|min:1',
        ]);

        if ($reason = WarehouseSimulator::blockedReason() ?? WarehouseSimulator::missingRequirement()) {
            return response()->json(['message' => $reason], 409);
        }
        if (! $run->start($data['days'], $data['orders'], $data['seed'] ?? null, $data['errors'])) {
            return response()->json(['message' => 'A simulation is already running.'], 409);
        }

        return response()->json(['simulation' => $run->status()], 202);
    }

    public function slowQueries(Request $request, SlowQueryLog $slow): JsonResponse
    {
        $data = $request->validate([
            'source' => 'nullable|in:all,app,db',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);
        $source = $data['source'] ?? 'all';
        $limit = $data['limit'] ?? 300;

        $entries = [];
        $db = ['available' => null, 'message' => null];
        if ($source !== 'db') {
            $entries = $slow->appEntries($limit);
        }
        if ($source !== 'app') {
            $result = $slow->dbEntries($limit);
            $db = ['available' => $result['available'], 'message' => $result['message']];
            $entries = array_merge($entries, $result['entries']);
        }
        usort($entries, fn ($a, $b) => strcmp($b['time'], $a['time']));

        return response()->json([
            'entries' => array_slice(array_values(array_filter($entries, fn ($e) => ($e['kind'] ?? 'query') === 'query')), 0, $limit),
            'groups' => array_slice($slow->group($entries), 0, 50),
            'requests' => array_slice($slow->groupRequests($entries), 0, 50),
            'db' => $db,
        ]);
    }

    public function clearSlowQueries(SlowQueryLog $slow): JsonResponse
    {
        $slow->clearApp();

        return response()->json(['success' => true]);
    }
}
