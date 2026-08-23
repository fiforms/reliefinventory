<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use League\Csv\Writer;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Every order not yet Shipped — the "what's still owed to a partner" view
 * that Order Entry's own list doesn't provide on its own (that page is an
 * entry tool, not a report: no export, no cross-order line totals). Reuses
 * the same "open" status set as OrderController::index()'s "open" bucket.
 */
class OutstandingOrdersReportController extends Controller
{
    private const OUTSTANDING_STATUSES = [
        Transaction::STATUS_NEW_ORDER,
        Transaction::STATUS_READY_TO_FILL,
        Transaction::STATUS_FILLING,
        Transaction::STATUS_FILLED,
    ];

    public function index()
    {
        return response()->json(['records' => $this->buildRecords()]);
    }

    public function pdf()
    {
        $records = $this->buildRecords();
        $generatedAt = now();

        return Pdf::view('reports.orders', ['records' => $records, 'generatedAt' => $generatedAt])
            ->driver('weasyprint')
            ->format('letter')
            ->name('outstanding-orders-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    public function csv()
    {
        $records = $this->buildRecords();

        $csv = Writer::createFromString('');
        $csv->insertOne(['Order #', 'Partner', 'Status', 'Order Date', 'Needed By', 'Fulfillment', 'Lines', 'Qty Requested']);
        foreach ($records as $r) {
            $csv->insertOne([
                $r['id'],
                $r['partner'],
                $r['status'],
                $r['order_date'],
                $r['needed_by_date'],
                $r['fulfillment_method'],
                $r['line_count'],
                $r['qty_requested'],
            ]);
        }

        $filename = 'outstanding-orders-'.now()->format('Y-m-d').'.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function buildRecords(): Collection
    {
        $statusIds = collect(self::OUTSTANDING_STATUSES)->map(fn ($name) => Transaction::statusId($name));

        return Transaction::where('type', 'order')
            ->whereIn('status_id', $statusIds)
            ->with(['person', 'status', 'orderLines.itemtype.unit'])
            ->orderByRaw('needed_by_date is null, needed_by_date asc')
            ->orderBy('order_date', 'asc')
            ->get()
            ->map(fn (Transaction $order) => [
                'id' => $order->id,
                'partner' => $order->person?->full_name,
                'status' => $order->status?->name,
                'order_date' => $order->order_date,
                'needed_by_date' => $order->needed_by_date,
                'fulfillment_method' => $order->fulfillment_method,
                'contact_name' => $order->contact_name,
                'contact_phone' => $order->contact_phone,
                'line_count' => $order->orderLines->count(),
                'qty_requested' => (int) $order->orderLines->sum('qty_requested'),
                'lines' => $order->orderLines->map(fn ($line) => [
                    'itemtype' => $line->itemtype?->name,
                    'display_number' => $line->itemtype?->display_number,
                    'unit' => $line->itemtype?->unit?->abbreviation ?? $line->itemtype?->unit?->name,
                    'qty_requested' => (int) $line->qty_requested,
                ])->values(),
            ])
            ->values();
    }
}
