<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\ItemType;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Order intake sessions.
 *
 * Like donation sorting (and unlike the RIForm document model), order entry
 * is an event stream: the order header is created the moment a customer is
 * confirmed, and each requested line is committed as it is entered. A crash
 * or dropped connection never loses more than the line being typed. Phone
 * orders and hand-entered PDF order forms both come through this same API —
 * they are the same activity (a volunteer rapid-keying item numbers).
 *
 * Orders are only editable while in "New Order" status; once filling starts
 * the order locks against intake edits (server-enforced, mirroring sorting's
 * completed-session rule).
 */
class OrderController extends Controller
{
    private const WITH_RELATIONS = [
        'person.county',
        'enteredBy',
        'status',
        'orderLines.itemtype.unit',
        'itemLedgers.item.itemtype',
    ];

    private const LINE_VALIDATION = [
        'itemtype_id' => 'required|exists:itemtypes,id',
        'qty_requested' => 'required|integer|min:1',
        'comments' => 'nullable|string',
    ];

    /**
     * Intake edits are only allowed while the order is still "New Order" —
     * once filling has begun, changing the requested lines would silently
     * desync what the floor is picking from what the customer sees.
     */
    private function rejectIfLocked(Transaction $order)
    {
        if ($order->status?->name !== Transaction::STATUS_NEW_ORDER) {
            abort(response()->json([
                'message' => 'This order is being filled and can no longer be edited.',
            ], 409));
        }
    }

    private function orderQuery()
    {
        return Transaction::where('type', 'order')
            ->with(self::WITH_RELATIONS);
    }

    /**
     * Open orders (still in intake or being filled) and recently
     * shipped/completed ones.
     */
    public function index()
    {
        $openStatuses = [
            Transaction::statusId(Transaction::STATUS_NEW_ORDER),
            Transaction::statusId(Transaction::STATUS_FILLING),
            Transaction::statusId(Transaction::STATUS_FILLED),
        ];

        return response()->json([
            'open' => $this->orderQuery()
                ->whereIn('status_id', $openStatuses)
                ->orderBy('id', 'desc')
                ->get(),
            'recent' => $this->orderQuery()
                ->whereNotIn('status_id', $openStatuses)
                ->orderBy('id', 'desc')
                ->limit(25)
                ->get(),
        ]);
    }

    /**
     * Advisory usable stock on hand per itemtype, for the intake screen's
     * "~N on hand" hint. Staff-facing only — customer-facing surfaces must
     * never show actual quantities (three-state availability at most).
     */
    public function stockHints()
    {
        // Ledger rows predating the disposition column count as usable.
        $hints = DB::table('item_ledgers')
            ->join('items', 'items.id', '=', 'item_ledgers.item_id')
            ->groupBy('items.itemtype_id')
            ->selectRaw("items.itemtype_id,
                SUM(CASE WHEN COALESCE(item_ledgers.disposition, 'usable') = 'usable'
                    THEN COALESCE(item_ledgers.qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(item_ledgers.qty_subtracted, 0)) AS on_hand")
            ->pluck('on_hand', 'itemtype_id');

        return response()->json(['hints' => $hints]);
    }

    /**
     * Open a new order for a confirmed customer. Status is system-controlled:
     * every order starts as "New Order" and progresses via filling actions,
     * never from the form.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'person_id' => 'required|exists:people,id',
            'order_date' => 'nullable|date',
            'comments' => 'nullable|string',
        ]);

        $order = Transaction::create([
            'type' => 'order',
            'person_id' => $data['person_id'],
            'person_id_user' => Auth::id(),
            'status_id' => Transaction::statusId(Transaction::STATUS_NEW_ORDER),
            'order_date' => $data['order_date'] ?? now()->toDateString(),
            'comments' => $data['comments'] ?? null,
        ]);

        return response()->json([
            'record' => $order->load(self::WITH_RELATIONS),
        ], 201);
    }

    public function show($id)
    {
        return response()->json([
            'record' => $this->orderQuery()->findOrFail($id),
        ]);
    }

    /**
     * Update order header fields (customer, date, comments).
     */
    public function update(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectIfLocked($order);

        $data = $request->validate([
            'person_id' => 'sometimes|required|exists:people,id',
            'order_date' => 'sometimes|required|date',
            'comments' => 'nullable|string',
        ]);

        $order->fill($data)->save();

        return response()->json([
            'record' => $order->load(self::WITH_RELATIONS),
        ]);
    }

    public function destroy($id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectIfLocked($order);

        DB::transaction(function () use ($order) {
            $order->orderLines()->delete();
            $order->delete();
        });

        return response()->json(['success' => true]);
    }

    /**
     * Append one requested line as it is entered.
     */
    public function storeLine(Request $request, $id)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectIfLocked($order);
        $data = $request->validate(self::LINE_VALIDATION);

        $line = $order->orderLines()->create($data);

        return response()->json([
            'record' => $line->load('itemtype.unit'),
        ], 201);
    }

    /**
     * Correct a previously entered line (also used to combine duplicate
     * entries of the same item into one line).
     */
    public function updateLine(Request $request, $id, $lineId)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectIfLocked($order);
        $line = $order->orderLines()->findOrFail($lineId);

        $data = $request->validate([
            'itemtype_id' => 'sometimes|required|exists:itemtypes,id',
            'qty_requested' => 'sometimes|required|integer|min:1',
            'comments' => 'nullable|string',
        ]);

        $line->fill($data)->save();

        return response()->json([
            'record' => $line->load('itemtype.unit'),
        ]);
    }

    public function destroyLine($id, $lineId)
    {
        $order = Transaction::where('type', 'order')->findOrFail($id);
        $this->rejectIfLocked($order);
        $order->orderLines()->findOrFail($lineId)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Offline order form: printed or emailed to a POD/customer who fills in
     * quantities by hand and returns it, to be hand-keyed in as a
     * volunteer-transcription order (same intake channel as a phone order —
     * see the order-intake design notes). Deliberately never prints an
     * actual stock number: presence in this list ("we have some") is a
     * request-eligible signal, not a promise, and that boundary lives in
     * the query itself (on_hand > 0), never rendered.
     *
     * sort_hold item types are excluded — they're valid at the sorting
     * table but not yet supervisor-reviewed into a real orderable number.
     */
    public function orderFormPdf()
    {
        $generatedAt = now();

        return Pdf::view('reports.order-form', ['categories' => $this->buildOrderFormRecords(), 'generatedAt' => $generatedAt])
            ->headerView('reports.partials.pdf-header', ['title' => 'Order Request Form', 'generatedAt' => $generatedAt])
            ->margins(top: 0.75, right: 0, bottom: 1, left: 0, unit: 'in')
            ->format('letter')
            ->name('order-form-'.$generatedAt->format('Y-m-d').'.pdf');
    }

    public function buildOrderFormRecords()
    {
        $rows = DB::table('itemtypes')
            ->join('items', 'items.itemtype_id', '=', 'itemtypes.id')
            ->leftJoin('item_ledgers', 'item_ledgers.item_id', '=', 'items.id')
            ->leftJoin('categories', 'categories.id', '=', 'itemtypes.category_id')
            ->leftJoin('units', 'units.id', '=', 'itemtypes.unit_id')
            ->where('itemtypes.status', 'orderable')
            ->groupBy('itemtypes.id', 'itemtypes.family', 'itemtypes.variant', 'itemtypes.name', 'categories.name', 'units.abbreviation', 'units.name')
            ->havingRaw("
                SUM(CASE WHEN COALESCE(item_ledgers.disposition, 'usable') = 'usable'
                    THEN COALESCE(item_ledgers.qty_added, 0) ELSE 0 END)
                - SUM(COALESCE(item_ledgers.qty_subtracted, 0)) > 0
            ")
            ->selectRaw('itemtypes.id, itemtypes.family, itemtypes.variant, itemtypes.name, categories.name as category, COALESCE(units.abbreviation, units.name) as unit')
            ->get();

        $records = $rows->map(fn ($row) => [
            'display_number' => (new ItemType(['family' => $row->family, 'variant' => $row->variant]))->display_number,
            'name' => $row->name,
            'category' => $row->category,
            'unit' => $row->unit,
        ])->sortBy([['category', 'asc'], ['display_number', 'asc']])->values();

        return $records->groupBy('category');
    }
}
