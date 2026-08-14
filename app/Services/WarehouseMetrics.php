<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared aggregation layer behind both the internal Warehouse Dashboard
 * (full detail) and the external Situation Report (restricted subset) —
 * one place computes "what's happening," so the two views can never
 * silently disagree with each other. The Situation Report's controller is
 * responsible for only forwarding the fields that are safe to show
 * externally; this service itself does not filter for audience.
 *
 * Periods are trailing windows (last 24h / 7 days / 30 days), not calendar-
 * aligned weeks/months — simpler to reason about and makes the trend
 * comparison (this window vs. the one immediately before it) apples-to-
 * apples regardless of what day "now" happens to be.
 */
class WarehouseMetrics
{
    private const TREND_STATIC_THRESHOLD_PCT = 5.0;

    /**
     * Orders currently sitting in Filled or Shipped are "fulfilled" —
     * status_changed_at reflects the moment an order most recently entered
     * its current status, which for a still-Filled or still-Shipped order
     * is exactly the fulfillment moment. An order only ever holds one
     * current status at a time, so this never double-counts a Filled order
     * that later ships.
     */
    public function ordersFulfilledCounts(): array
    {
        return $this->periodCounts(
            fn (Carbon $since) => Transaction::where('type', 'order')
                ->whereHas('status', fn ($q) => $q->whereIn('name', [Transaction::STATUS_FILLED, Transaction::STATUS_SHIPPED]))
                ->where('status_changed_at', '>=', $since)
                ->count(),
            fn () => Transaction::where('type', 'order')
                ->whereHas('status', fn ($q) => $q->whereIn('name', [Transaction::STATUS_FILLED, Transaction::STATUS_SHIPPED]))
                ->count()
        );
    }

    /**
     * Donations reaching Complete — same "current status is the terminal
     * one" logic as orders above.
     */
    public function donationsCompletedCounts(): array
    {
        return $this->periodCounts(
            fn (Carbon $since) => Transaction::where('type', 'donation')
                ->whereHas('status', fn ($q) => $q->where('name', Transaction::STATUS_COMPLETE))
                ->where('status_changed_at', '>=', $since)
                ->count(),
            fn () => Transaction::where('type', 'donation')
                ->whereHas('status', fn ($q) => $q->where('name', Transaction::STATUS_COMPLETE))
                ->count()
        );
    }

    private function periodCounts(callable $countSince, callable $countAllTime): array
    {
        return [
            'today' => $countSince(Carbon::now()->subDay()),
            'last_7_days' => $countSince(Carbon::now()->subDays(7)),
            'last_30_days' => $countSince(Carbon::now()->subDays(30)),
            'all_time' => $countAllTime(),
        ];
    }

    /**
     * Up/down/static comparison of a trailing window against the equal-
     * length window immediately before it. $counter takes (since, until)
     * and returns a count for that half-open window.
     */
    public function trend(callable $counter, int $days): array
    {
        $now = Carbon::now();
        $windowStart = (clone $now)->subDays($days);
        $priorStart = (clone $windowStart)->subDays($days);

        $current = $counter($windowStart, $now);
        $prior = $counter($priorStart, $windowStart);

        if ($prior === 0) {
            $direction = $current === 0 ? 'static' : 'up';
            $percent = null; // undefined with a zero base — direction still meaningful, magnitude isn't
        } else {
            $percent = round((($current - $prior) / $prior) * 100, 1);
            $direction = abs($percent) < self::TREND_STATIC_THRESHOLD_PCT
                ? 'static'
                : ($percent > 0 ? 'up' : 'down');
        }

        return ['current' => $current, 'prior' => $prior, 'direction' => $direction, 'percent' => $percent];
    }

    public function ordersTrend(int $days = 7): array
    {
        return $this->trend(fn (Carbon $since, Carbon $until) => Transaction::where('type', 'order')
            ->whereHas('status', fn ($q) => $q->whereIn('name', [Transaction::STATUS_FILLED, Transaction::STATUS_SHIPPED]))
            ->whereBetween('status_changed_at', [$since, $until])
            ->count(), $days);
    }

    public function donationsTrend(int $days = 7): array
    {
        return $this->trend(fn (Carbon $since, Carbon $until) => Transaction::where('type', 'donation')
            ->whereHas('status', fn ($q) => $q->where('name', Transaction::STATUS_COMPLETE))
            ->whereBetween('status_changed_at', [$since, $until])
            ->count(), $days);
    }

    /**
     * Current snapshot of where every open donation/order sits in its
     * lifecycle — the "what's in flight right now" view a dashboard needs
     * that a historical count can't answer.
     */
    public function pipelineCounts(): array
    {
        $byStatus = fn (string $type, array $statuses) => Transaction::where('type', $type)
            ->join('statuses', 'statuses.id', '=', 'orderdonations.status_id')
            ->whereIn('statuses.name', $statuses)
            ->selectRaw('statuses.name, count(*) as count')
            ->groupBy('statuses.name')
            ->pluck('count', 'name')
            ->all();

        $donationStatuses = [Transaction::STATUS_RECEIVED, Transaction::STATUS_SORTING, Transaction::STATUS_COMPLETE];
        $orderStatuses = [Transaction::STATUS_NEW_ORDER, Transaction::STATUS_FILLING, Transaction::STATUS_FILLED, Transaction::STATUS_SHIPPED];

        $donationCounts = $byStatus('donation', $donationStatuses);
        $orderCounts = $byStatus('order', $orderStatuses);

        return [
            'donations' => collect($donationStatuses)->mapWithKeys(fn ($s) => [$s => $donationCounts[$s] ?? 0])->all(),
            'orders' => collect($orderStatuses)->mapWithKeys(fn ($s) => [$s => $orderCounts[$s] ?? 0])->all(),
        ];
    }

    /**
     * Order counts by customer county — county only, never a name or
     * organization. Safe for both the internal dashboard and the
     * externally-shared Situation Report.
     */
    public function orderCountyBreakdown(): array
    {
        return DB::table('orderdonations')
            ->join('people', 'people.id', '=', 'orderdonations.person_id')
            ->leftJoin('counties', 'counties.id', '=', 'people.county_id')
            ->where('orderdonations.type', 'order')
            ->selectRaw("COALESCE(counties.county, 'Unspecified') as county, count(*) as count")
            ->groupBy('county')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['county' => $row->county, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * Compact stock-on-hand summary — the full breakdown lives in the
     * Inventory Report; this is just enough for a dashboard card with a
     * link out to that report for detail.
     */
    public function inventorySummary(): array
    {
        $rows = DB::table('items')
            ->leftJoin('item_ledgers', 'item_ledgers.item_id', '=', 'items.id')
            ->join('itemtypes', 'itemtypes.id', '=', 'items.itemtype_id')
            ->leftJoin('categories', 'categories.id', '=', 'itemtypes.category_id')
            ->groupBy('items.itemtype_id', 'categories.name')
            ->selectRaw("
                items.itemtype_id, categories.name as category,
                SUM(CASE WHEN COALESCE(item_ledgers.disposition, 'usable') = 'usable'
                    THEN COALESCE(item_ledgers.qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(item_ledgers.qty_subtracted, 0)) AS on_hand
            ")
            ->get();

        $withStock = $rows->filter(fn ($r) => $r->on_hand > 0);

        $byCategory = $withStock->groupBy('category')
            ->map(fn ($group, $category) => ['category' => $category ?? 'Uncategorized', 'units' => (int) $group->sum('on_hand')])
            ->sortByDesc('units')
            ->values();

        return [
            'item_types_with_stock' => $withStock->count(),
            'total_units_on_hand' => (int) $withStock->sum('on_hand'),
            'top_categories' => $byCategory->take(5)->all(),
        ];
    }

    /**
     * Overall donor-quality signal: what fraction of everything sorted in
     * the trailing window was usable vs. lost (outdated/trashed). Diverted
     * goods are usable-but-elsewhere, so they're excluded from the loss
     * rate rather than counted against donor quality.
     */
    public function donorQualitySummary(int $days = 30): array
    {
        $totals = DB::table('item_ledgers')
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->whereNotNull('qty_added')
            ->selectRaw("
                SUM(CASE WHEN disposition = 'usable' THEN qty_added ELSE 0 END) as usable,
                SUM(CASE WHEN disposition = 'outdated' THEN qty_added ELSE 0 END) as outdated,
                SUM(CASE WHEN disposition = 'trashed' THEN qty_added ELSE 0 END) as trashed,
                SUM(CASE WHEN disposition = 'diverted' THEN qty_added ELSE 0 END) as diverted
            ")
            ->first();

        $usable = (int) ($totals->usable ?? 0);
        $outdated = (int) ($totals->outdated ?? 0);
        $trashed = (int) ($totals->trashed ?? 0);
        $diverted = (int) ($totals->diverted ?? 0);
        $total = $usable + $outdated + $trashed + $diverted;

        return [
            'usable' => $usable,
            'outdated' => $outdated,
            'trashed' => $trashed,
            'diverted' => $diverted,
            'loss_rate_percent' => $total > 0 ? round((($outdated + $trashed) / $total) * 100, 1) : null,
        ];
    }
}
