<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\WarehouseMetrics;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * External Situation Report — a restricted subset of WarehouseMetrics
 * meant to be shared outside the organization (FEMA/state liaisons):
 * movement counts, trends, county-level order distribution, and a coarse
 * stock summary. Deliberately excludes anything that could identify a
 * specific person, donor, or order — no names, no addresses, no per-item
 * SKU detail, no donor-quality figures (an internal donor-relationship
 * metric, not needed for external situational awareness).
 *
 * This is a live, informal snapshot ("here's what's moving right now"),
 * not the organization's official reporting — both the on-screen view and
 * the PDF export pull from the same buildData() call, so they can never
 * show different numbers for the same moment.
 */
class SitrepController extends Controller
{
    private function buildData(WarehouseMetrics $metrics): array
    {
        return [
            'orders_fulfilled' => $metrics->ordersFulfilledCounts(),
            'donations_completed' => $metrics->donationsCompletedCounts(),
            'orders_trend' => $metrics->ordersTrend(),
            'donations_trend' => $metrics->donationsTrend(),
            'pipeline' => $metrics->pipelineCounts(),
            'county_breakdown' => $metrics->orderCountyBreakdown(),
            'inventory_summary' => $metrics->inventorySummary(),
            'generated_at' => now(),
        ];
    }

    public function index(WarehouseMetrics $metrics)
    {
        return response()->json($this->buildData($metrics));
    }

    public function pdf(WarehouseMetrics $metrics)
    {
        return Pdf::view('reports.sitrep', ['data' => $this->buildData($metrics)])
            ->format('letter')
            ->name('situation-report-'.now()->format('Y-m-d').'.pdf');
    }
}
