<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\County;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Partner Report: how much each partner (distribution point) has requested
 * and received over a date range — requests, units requested vs. filled,
 * shipments and deliveries, last request — with a roll-up by county and a
 * drill-down to each partner's requests.
 *
 * Every request counts (whatever its status) toward "requests" and
 * "requested", and filled units are the order's fill records
 * (item_ledgers.order_line_id), like the Unfulfilled Requests Report. The
 * fill rate only looks at requests whose filling is finished
 * (UnfulfilledRequestsReportController::FILLED_STATUSES) — a request still
 * waiting to be filled would otherwise drag every partner's rate down.
 * What was short, and why, lives in /reports/unfulfilled. Quantities are in
 * each item's own unit, so units summed across items are a volume, not a
 * weight.
 */
class PartnerReportController extends Controller
{
    public const FILTER_RULES = [
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        'county_id' => 'nullable|integer|exists:counties,id',
    ];

    private const SHIPPED = [
        Transaction::STATUS_SHIPPED, Transaction::STATUS_DELIVERED,
        Transaction::STATUS_DELIVERED_AWAITING_BOL, Transaction::STATUS_COMPLETED,
    ];

    private const DELIVERED = [
        Transaction::STATUS_DELIVERED, Transaction::STATUS_DELIVERED_AWAITING_BOL, Transaction::STATUS_COMPLETED,
    ];

    public function index(Request $request)
    {
        return response()->json([
            ...$this->buildReport($this->filters($request)),
            'options' => ['counties' => County::orderBy('county')->get(['id', 'county', 'state'])],
        ]);
    }

    public function pdf(Request $request)
    {
        return $this->pdfBuilder($this->filters($request));
    }

    /** Unrendered PDF — shared by the download route and the print queue. */
    public function pdfBuilder(array $filters = []): PdfBuilder
    {
        $generatedAt = now();

        return Pdf::view('reports.partners', [
            'report' => $this->buildReport($filters),
            'generatedAt' => $generatedAt,
        ])
            ->driver('weasyprint')
            ->format('letter')
            ->name('partner-report-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    /** One row per partner request — the finest grain, so it pivots in a spreadsheet. */
    public function csv(Request $request)
    {
        $report = $this->buildReport($this->filters($request));

        $csv = Writer::fromString('');
        $csv->insertOne(['Partner', 'County', 'Zip', 'Request #', 'Order Date', 'Status', 'Qty Requested', 'Qty Filled']);
        foreach ($report['partners'] as $partner) {
            foreach ($partner['orders'] as $order) {
                $csv->insertOne([
                    $partner['name'], $partner['county'], $partner['zip'], $order['id'],
                    $order['order_date'], $order['status'], $order['requested'], $order['filled'],
                ]);
            }
        }

        $filename = 'partner-report-'.now()->format('Y-m-d').'.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate(self::FILTER_RULES));
    }

    /**
     * @param  array{from?: string, to?: string, county_id?: int}  $filters  order_date range, inclusive
     */
    public function buildReport(array $filters = []): array
    {
        $orders = $this->orders($filters);
        $finished = collect(UnfulfilledRequestsReportController::FILLED_STATUSES);

        $partners = $orders->groupBy(fn ($o) => $o->person_id ?? 0)
            ->map(function (Collection $rows) use ($finished) {
                $first = $rows->first();
                $done = $rows->filter(fn ($o) => $finished->contains($o->status));
                $doneRequested = (int) $done->sum('requested');
                $doneFilled = (int) $done->sum('filled');

                return [
                    'person_id' => $first->person_id,
                    'name' => $this->partnerName($first),
                    'county' => $first->county ?? 'Unspecified',
                    'zip' => $first->zip ?: 'Unspecified',
                    'requests' => $rows->count(),
                    'requested' => (int) $rows->sum('requested'),
                    'filled' => (int) $rows->sum('filled'),
                    'finished_requests' => $done->count(),
                    'fill_rate' => $doneRequested > 0 ? round($doneFilled / $doneRequested * 100, 1) : null,
                    'shipped' => $rows->filter(fn ($o) => in_array($o->status, self::SHIPPED, true))->count(),
                    'delivered' => $rows->filter(fn ($o) => in_array($o->status, self::DELIVERED, true))->count(),
                    'last_request' => $rows->max('order_date'),
                    'orders' => $rows->sortByDesc('order_date')->map(fn ($o) => [
                        'id' => (int) $o->id,
                        'order_date' => $o->order_date,
                        'status' => $o->status,
                        'requested' => (int) $o->requested,
                        'filled' => (int) $o->filled,
                    ])->values(),
                ];
            })
            ->sortBy([['requested', 'desc'], ['name', 'asc']])
            ->values();

        $byCounty = $partners->groupBy('county')
            ->map(fn (Collection $rows, $county) => [
                'county' => (string) $county,
                'partners' => $rows->count(),
                'requests' => (int) $rows->sum('requests'),
                'requested' => (int) $rows->sum('requested'),
                'filled' => (int) $rows->sum('filled'),
            ])
            ->sortByDesc('requested')
            ->values();

        $doneRequested = (int) $orders->filter(fn ($o) => $finished->contains($o->status))->sum('requested');
        $doneFilled = (int) $orders->filter(fn ($o) => $finished->contains($o->status))->sum('filled');

        return [
            'filters' => (object) $filters,
            'partners' => $partners,
            'by_county' => $byCounty,
            'totals' => [
                'partners' => $partners->count(),
                'requests' => $orders->count(),
                'requested' => (int) $orders->sum('requested'),
                'filled' => (int) $orders->sum('filled'),
                'fill_rate' => $doneRequested > 0 ? round($doneFilled / $doneRequested * 100, 1) : null,
                'shipped' => (int) $partners->sum('shipped'),
                'delivered' => (int) $partners->sum('delivered'),
            ],
        ];
    }

    private function partnerName(object $row): string
    {
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return $row->organization ?: ($name !== '' ? $name : '(no partner)');
    }

    /** One row per request, with units requested/filled summed from its lines and fill records. */
    private function orders(array $filters): Collection
    {
        $lines = DB::table('orderlines')
            ->groupBy('orderdonation_id')
            ->selectRaw('orderdonation_id, SUM(qty_requested) AS requested');

        $fills = DB::table('item_ledgers')
            ->join('orderlines', 'orderlines.id', '=', 'item_ledgers.order_line_id')
            ->groupBy('orderlines.orderdonation_id')
            ->selectRaw('orderlines.orderdonation_id, SUM(COALESCE(item_ledgers.qty_subtracted, 0)) AS filled');

        return DB::table('orderdonations')
            ->leftJoin('people', 'people.id', '=', 'orderdonations.person_id')
            ->leftJoin('counties', 'counties.id', '=', 'people.county_id')
            ->leftJoin('statuses', 'statuses.id', '=', 'orderdonations.status_id')
            ->leftJoinSub($lines, 'lines', 'lines.orderdonation_id', '=', 'orderdonations.id')
            ->leftJoinSub($fills, 'fills', 'fills.orderdonation_id', '=', 'orderdonations.id')
            ->where('orderdonations.type', 'order')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('orderdonations.order_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('orderdonations.order_date', '<=', $to))
            ->when($filters['county_id'] ?? null, fn ($q, $id) => $q->where('people.county_id', $id))
            ->selectRaw('
                orderdonations.id, orderdonations.person_id, orderdonations.order_date,
                statuses.name AS status,
                people.first_name, people.last_name, people.organization, people.zip,
                counties.county AS county,
                COALESCE(lines.requested, 0) AS requested,
                COALESCE(fills.filled, 0) AS filled
            ')
            ->get();
    }
}
