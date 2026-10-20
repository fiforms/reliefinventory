<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Models\PalletStatus;
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
