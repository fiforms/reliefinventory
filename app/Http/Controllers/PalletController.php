<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Pallet;
use App\Support\PalletKind;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PalletController extends Controller
{
    /**
     * Display a listing of the pallets, optionally filtered by kind and/or
     * status (e.g. GET /json/pallets/R/sorting).
     */
    public function index(?string $kind = null, ?string $status = null)
    {
        $query = Pallet::with(['location', 'donor', 'destination', 'truck']);

        if ($kind) {
            $query->where('kind', $kind);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $pallets = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'records' => $pallets,
            'templates' => [
                '_default' => [
                    'kind' => PalletKind::RECEIVING,
                    'status' => PalletKind::initialStatus(PalletKind::RECEIVING),
                    'container_type' => 'pallet',
                    'location_id' => null,
                    'donor_person_id' => null,
                    'destination_person_id' => null,
                    'truck_id' => null,
                    'datepacked' => now()->toDateString(),
                    'earliest_expiry' => null,
                    'condition' => null,
                ],
            ],
            'kinds' => PalletKind::LABELS,
        ]);
    }

    /**
     * Create a new pallet. Kind is fixed forever once set — reusing
     * physical wood for a new load always means a new record (LPN model),
     * never re-labeling an existing one.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PalletKind::LIFECYCLES))],
            'container_type' => ['nullable', Rule::in(['pallet', 'gaylord', 'box', 'bag'])],
            'location_id' => 'nullable|exists:locations,id',
            'donor_person_id' => 'nullable|exists:people,id',
            'destination_person_id' => 'nullable|exists:people,id',
            'truck_id' => 'nullable|exists:trucks,id',
            'datepacked' => 'nullable|date',
            'earliest_expiry' => 'nullable|date',
        ]);

        $data['status'] = PalletKind::initialStatus($data['kind']);
        $data['datepacked'] = $data['datepacked'] ?? now()->toDateString();

        $pallet = Pallet::create($data);

        $pallet->statuses()->create([
            'location_id' => $pallet->location_id,
            'status' => $pallet->status,
        ]);

        return response()->json([
            'status' => 'Pallet created successfully.',
            'record' => $pallet->load(['location', 'donor', 'destination', 'truck']),
        ], 201);
    }

    /**
     * Update an existing pallet. A status change (including to/from
     * "missing") is logged to history via Pallet::transitionTo()/
     * markMissing()/restoreFromMissing() rather than a bare column write,
     * so the audit trail never drifts out of sync with the live record.
     */
    public function update(Request $request, $id)
    {
        $pallet = Pallet::findOrFail($id);

        $data = $request->validate([
            'status' => 'nullable|string',
            'location_id' => 'nullable|exists:locations,id',
            'container_type' => ['nullable', Rule::in(['pallet', 'gaylord', 'box', 'bag'])],
            'donor_person_id' => 'nullable|exists:people,id',
            'destination_person_id' => 'nullable|exists:people,id',
            'truck_id' => 'nullable|exists:trucks,id',
            'earliest_expiry' => 'nullable|date',
            'condition' => ['nullable', Rule::in(['pending', 'good', 'condemned'])],
            'notes' => 'nullable|string',
        ]);

        if (! empty($data['status']) && $data['status'] !== $pallet->status
            && $data['status'] !== PalletKind::MISSING
            && ! PalletKind::isValidStatus($pallet->kind, $data['status'])) {
            return response()->json([
                'errors' => ['status' => ["\"{$data['status']}\" is not a valid status for a ".(PalletKind::LABELS[$pallet->kind] ?? $pallet->kind).' pallet.']],
            ], 422);
        }

        $pallet->fill(collect($data)->except(['status', 'location_id', 'notes'])->toArray());
        $pallet->save();

        if (! empty($data['status']) && $data['status'] !== $pallet->status) {
            if ($data['status'] === PalletKind::MISSING) {
                $pallet->markMissing($data['notes'] ?? null);
            } elseif ($pallet->status === PalletKind::MISSING && $data['status'] === $pallet->status_before_missing) {
                $pallet->restoreFromMissing();
            } else {
                $pallet->transitionTo($data['status'], $data['location_id'] ?? null, $data['notes'] ?? null);
            }
        } elseif (! empty($data['location_id']) && $data['location_id'] !== $pallet->getOriginal('location_id')) {
            // Location-only move: still logged, same status.
            $pallet->transitionTo($pallet->status, $data['location_id'], $data['notes'] ?? null);
        }

        return response()->json([
            'message' => 'Pallet updated successfully.',
            'record' => $pallet->fresh(['location', 'donor', 'destination', 'truck', 'statuses.location']),
        ], 200);
    }

    /**
     * Remove the specified pallet from storage.
     */
    public function destroy($id)
    {
        $pallet = Pallet::findOrFail($id);
        $pallet->delete();

        return response()->json([
            'message' => 'Pallet deleted successfully.',
        ], 200);
    }
}
