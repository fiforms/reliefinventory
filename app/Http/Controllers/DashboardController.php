<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
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

    /**
     * The same numbers for an unattended R Station display (no login): aggregates only — the donor
     * quality block is left out, since a wall screen is seen by whoever walks past.
     */
    public function display(WarehouseMetrics $metrics)
    {
        return response()->json(collect($this->index($metrics)->getData(true))->except('donor_quality'));
    }
}
