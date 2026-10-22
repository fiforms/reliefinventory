<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemLedger;
use App\Models\Location;
use App\Models\Pallet;
use App\Models\PalletStatus;
use App\Models\PalletStockAssignment;
use App\Support\InventoryLabelCode;
use App\Support\PalletKind;
use Illuminate\Http\Request;

/**
 * Read/audit access to pallet status history, plus a movement-logging entry
 * point for the standalone "Pallet Location Tracking" page. Writes always
 * go through Pallet::transitionTo() (the same path PalletController::update
 * uses) so there is exactly one place that keeps pallets and their history
 * in sync — never duplicated logic that could drift.
 */
class PalletStatusController extends Controller
{
    private const VALIDATION = [
        'pallet_id' => 'required|exists:pallets,id',
        'location_id' => 'nullable|exists:locations,id',
        'status' => 'required|string',
        'notes' => 'nullable|string',
    ];

    // The history only ever grows (every scan, move and status change is a row), so the list is the most
    // recent movements, not all of them. ?pallet_id=N gives one pallet's full history; ?limit=N changes the cap.
    private const DEFAULT_LIMIT = 300;

    private const MAX_LIMIT = 2000;

    public function index(Request $request)
    {
        $query = PalletStatus::with(['pallet', 'location'])->orderByDesc('created_at')->orderByDesc('id');

        if ($request->filled('pallet_id')) {
            $query->where('pallet_id', (int) $request->query('pallet_id'));
        }

        $palletStatuses = $query->limit(min(max((int) $request->query('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT))->get();

        return response()->json([
            'records' => $palletStatuses,
            'templates' => [
                '_default' => [
                    'pallet_id' => null,
                    'location_id' => null,
                    'status' => null,
                    'notes' => null,
                    'pallet' => null,
                    'location' => null,
                ],
            ],
        ]);
    }

    /**
     * Status options are kind-specific, so this needs the pallet's kind
     * (e.g. GET /json/palletstatus/statuses?kind=W) rather than one global list.
     */
    public function statuses(Request $request)
    {
        $kind = $request->query('kind');
        $lifecycle = PalletKind::LIFECYCLES[$kind] ?? array_unique(array_merge(...array_values(PalletKind::LIFECYCLES)));

        $statuses = array_map(fn ($status) => ['id' => $status, 'name' => ucfirst($status)], $lifecycle);
        $statuses[] = ['id' => PalletKind::MISSING, 'name' => 'Missing'];

        return response()->json(['records' => $statuses]);
    }

    /**
     * Where everything is right now: every active location in pull order with
     * the live pallets in it, broken down by status, plus a bucket for live
     * pallets with no location. "Live" means still physically around — closed-out
     * (shipped/collected/released/condemned) and missing pallets aren't counted;
     * an emptied pallet still occupies its spot, so it is.
     */
    public function summary()
    {
        // Emptied pallets aren't listed or counted (a separate empty-pallet report is a TODO idea).
        $gone = [PalletKind::MISSING, 'shipped', 'collected', 'released', 'condemned', 'empty'];
        $counts = Pallet::query()
            ->whereNotIn('status', $gone)
            ->selectRaw('location_id, status, count(*) as total')
            ->groupBy('location_id', 'status')
            ->get()->toBase()
            ->groupBy(fn ($row) => $row->location_id ?? 0);

        $bucket = function ($rows) {
            $byStatus = $rows ? $rows->pluck('total', 'status')->map(fn ($n) => (int) $n)->all() : [];

            return ['total' => array_sum($byStatus), 'by_status' => $byStatus];
        };

        $locations = Location::where('status', 'active')
            ->orderByRaw('PullSequence is null')->orderBy('PullSequence')->orderBy('code')
            ->get(['id', 'code', 'PullSequence'])
            ->map(fn ($location) => ['id' => $location->id, 'code' => $location->code] + $bucket($counts->get($location->id)));

        // Pallets sitting in an archived/unknown location still need to show up somewhere.
        $known = $locations->pluck('id')->all();
        $stray = $counts->except(array_merge($known, [0]))->flatten(1)->groupBy('status')
            ->map(fn ($rows) => (int) $rows->sum('total'));

        return response()->json([
            'locations' => $locations,
            'no_location' => $bucket($counts->get(0)),
            'other_locations' => ['total' => $stray->sum(), 'by_status' => $stray->all()],
        ]);
    }

    /**
     * Drill-down for the Locations tab: the live pallets (same definition of "live" as summary())
     * in one location, with status. $location is a location id, "none" (no location recorded) or
     * "other" (archived/unknown locations).
     */
    public function locationPallets(string $location)
    {
        $gone = [PalletKind::MISSING, 'shipped', 'collected', 'released', 'condemned', 'empty'];
        $query = Pallet::query()->whereNotIn('status', $gone)->with('location:id,code');

        if ($location === 'none') {
            $query->whereNull('location_id');
        } elseif ($location === 'other') {
            $query->whereNotNull('location_id')
                ->whereNotIn('location_id', Location::where('status', 'active')->select('id'));
        } else {
            $query->where('location_id', (int) $location);
        }

        $pallets = $query->orderBy('id')->limit(self::MAX_LIMIT)->get();
        $lastMoved = PalletStatus::whereIn('pallet_id', $pallets->pluck('id'))
            ->selectRaw('pallet_id, max(created_at) as moved')->groupBy('pallet_id')->pluck('moved', 'pallet_id');

        return response()->json(['records' => $pallets->map(fn ($p) => [
            'id' => $p->id,
            'tag' => $p->tag,
            'kind_label' => PalletKind::LABELS[$p->kind] ?? $p->kind,
            'status' => $p->status,
            'location' => $p->location?->code,
            'last_moved_at' => $lastMoved[$p->id] ?? null,
        ])->values()]);
    }

    /**
     * Resolve a scanned/typed pallet tag ("W00000042", "42") to the pallet and
     * where the database believes it is, so the movement form can tell whether
     * a move actually needs recording.
     */
    public function lookup(Request $request)
    {
        $tag = (string) $request->query('tag', '');

        // An inventory label (ACS:1234-01:5678) finds the pallet its stock was put on.
        $assignment = null;
        if ($assignmentId = InventoryLabelCode::parse($tag)) {
            $assignment = PalletStockAssignment::with('item.itemtype')->find($assignmentId);
            if (! $assignment) {
                return response()->json(['message' => 'Unknown inventory label: '.$tag], 404);
            }
            $id = $assignment->pallet_id;
        } else {
            $id = SortingSessionController::palletIdFromTag($tag);
        }
        $pallet = $id ? Pallet::with('location:id,code')->find($id) : null;

        if (! $pallet) {
            return response()->json(['message' => 'Unknown pallet: '.$tag], 404);
        }

        $last = $pallet->statuses()->with('location:id,code')->latest('id')->first();
        $stock = $pallet->kind === PalletKind::WAREHOUSE ? PalletStockController::payload($pallet) : null;

        return response()->json(['record' => [
            'id' => $pallet->id,
            'tag' => $pallet->tag,
            'kind' => $pallet->kind,
            'kind_label' => PalletKind::LABELS[$pallet->kind] ?? $pallet->kind,
            'status' => $pallet->status,
            'location_id' => $pallet->location_id,
            'location' => $pallet->location,
            'last_moved_at' => $last?->created_at,
            'expires_on' => $pallet->earliest_expiry,
            'contents' => $stock['contents'] ?? null,
            // What was put on it, one row per inventory label, for reprinting (see InventoryLabelController).
            'labels' => $stock['labels'] ?? null,
            'sorted_from' => $pallet->kind === PalletKind::RECEIVING ? $this->sortedFrom($pallet) : null,
            'statuses' => $this->statusOptions($pallet->kind),
            // Set only when the scan was an inventory label: which label, so the screen can point at it.
            'scanned_label' => $assignment ? [
                'id' => $assignment->id,
                'name' => $assignment->item?->description ?? $assignment->item?->itemtype?->name ?? '#'.$assignment->item_id,
                'qty' => $assignment->qty,
            ] : null,
        ]]);
    }

    /** What sorting has recorded coming off a Receiving pallet: quantity per item and disposition. */
    private function sortedFrom(Pallet $pallet): array
    {
        $rows = ItemLedger::where('pallet_id', $pallet->id)->where('qty_added', '>', 0)
            ->selectRaw("item_id, COALESCE(disposition, 'usable') as disposition, SUM(qty_added) as qty")
            ->groupBy('item_id', 'disposition')->get();
        $items = Item::with('itemtype:id,name')->whereIn('id', $rows->pluck('item_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'item_id' => $r->item_id,
            'name' => $items[$r->item_id]->description ?? $items[$r->item_id]->itemtype?->name ?? '#'.$r->item_id,
            'disposition' => $r->disposition,
            'qty' => (int) $r->qty,
        ])->sortBy('name')->values()->all();
    }

    private function statusOptions(?string $kind): array
    {
        $lifecycle = PalletKind::LIFECYCLES[$kind] ?? array_unique(array_merge(...array_values(PalletKind::LIFECYCLES)));
        $statuses = array_map(fn ($status) => ['id' => $status, 'name' => ucfirst($status)], array_values($lifecycle));
        $statuses[] = ['id' => PalletKind::MISSING, 'name' => 'Missing'];

        return $statuses;
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION);
        $pallet = Pallet::findOrFail($data['pallet_id']);

        if ($data['status'] === PalletKind::MISSING) {
            $pallet->markMissing($data['notes'] ?? null);
        } elseif (! PalletKind::isValidStatus($pallet->kind, $data['status'])) {
            return response()->json([
                'errors' => ['status' => ["\"{$data['status']}\" is not valid for a ".(PalletKind::LABELS[$pallet->kind] ?? $pallet->kind).' pallet.'],
                ],
            ], 422);
        } else {
            $pallet->transitionTo($data['status'], $data['location_id'] ?? null, $data['notes'] ?? null);
        }

        return response()->json([
            'message' => 'Pallet status recorded successfully.',
            'record' => $pallet->statuses()->latest('id')->first(),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        // Status history is an append-only audit trail — editing a past
        // entry in place would let the record silently drift from what
        // actually happened. Log a new entry via store() instead.
        return response()->json([
            'message' => 'Pallet status history cannot be edited after the fact. Log a new status change instead.',
        ], 405);
    }

    public function destroy($id)
    {
        return response()->json([
            'message' => 'Pallet status history cannot be deleted.',
        ], 405);
    }
}
