<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\WarehouseMetrics;

/**
 * Internal Warehouse Dashboard — full-detail view for management/admins
 * (view-dashboard). Nothing here is restricted; contrast with
 * SitrepController, which forwards only a public-safe subset of the same
 * underlying WarehouseMetrics data for external stakeholders.
 */
class DashboardController extends Controller
{
    public function index(WarehouseMetrics $metrics)
    {
        return response()->json([
            'orders_fulfilled' => $metrics->ordersFulfilledCounts(),
            'donations_completed' => $metrics->donationsCompletedCounts(),
            'orders_trend' => $metrics->ordersTrend(),
            'donations_trend' => $metrics->donationsTrend(),
            'pipeline' => $metrics->pipelineCounts(),
            'county_breakdown' => $metrics->orderCountyBreakdown(),
            'inventory_summary' => $metrics->inventorySummary(),
            'donor_quality' => $metrics->donorQualitySummary(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
