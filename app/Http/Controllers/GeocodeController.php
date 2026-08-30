<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\County;
use App\Services\GeocodioService;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    /**
     * Suggest city/state/zip/county for an entered address — a
     * *suggestion* only, never applied automatically: the frontend only
     * fills in fields that are still blank (never overwrites something
     * already typed/chosen), and the county picker stays fully editable
     * regardless. Intended flow (per Mark, 2026-08-30): ask for ZIP first
     * (quick to state on a call), then street address — geocod.io resolves
     * city/state/county confidently from street+zip alone (tested at
     * `rooftop` accuracy), and the partner reciting their full address
     * afterward just becomes a confirmation pass over already-filled
     * fields rather than the only source of them. If geocod.io's county
     * name doesn't match anything already in the local `counties` table
     * (naming variance, or a county missing from the reference data), we
     * still return the suggested name/state so the frontend can offer it
     * through the existing quick-add flow rather than silently dropping it.
     */
    public function lookupCounty(Request $request, GeocodioService $geocodio)
    {
        $data = $request->validate([
            'address' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
        ]);

        if (! $geocodio->enabled()) {
            return response()->json(['message' => 'Address lookup is not configured on this instance.'], 503);
        }

        $result = $geocodio->lookup($data);
        if (! $result || ! $result['county']) {
            return response()->json(['message' => 'Could not determine a county for that address.'], 422);
        }

        $county = County::whereRaw('LOWER(county) = ?', [strtolower($result['county'])])
            ->whereRaw('LOWER(state) = ?', [strtolower((string) $result['state'])])
            ->first();

        return response()->json([
            'county_id' => $county?->id,
            'county' => $result['county'],
            'state' => $result['state'],
            'city' => $result['city'],
            'zip' => $result['zip'],
            // The standardized street line — only ever offered as a choice
            // (see AddressConfirmModal.vue), never applied automatically.
            'address' => $result['address'],
            'accuracy' => $result['accuracy'],
            'accuracy_type' => $result['accuracy_type'],
        ]);
    }
}
