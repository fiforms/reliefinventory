<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Truck;
use Illuminate\Http\Request;

class TruckController extends Controller
{
    private const VALIDATION = [
        'donor_person_id' => 'nullable|exists:people,id',
        'status' => ['nullable', 'in:received,unloaded'],
        'trailer_number' => 'nullable|string|max:255',
        'rough_pallet_estimate' => 'nullable|integer|min:0',
        'contents_summary' => 'nullable|string',
        'manifest_weight_lbs' => 'nullable|numeric|min:0',
    ];

    public function index()
    {
        $trucks = Truck::with('donor')->orderBy('id', 'desc')->get();

        return response()->json([
            'records' => $trucks,
            'templates' => [
                '_default' => [
                    'donor_person_id' => null,
                    'status' => 'received',
                    'trailer_number' => null,
                    'rough_pallet_estimate' => null,
                    'contents_summary' => null,
                    'manifest_weight_lbs' => null,
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION);
        $data['status'] = $data['status'] ?? 'received';

        $truck = Truck::create($data);

        return response()->json(['record' => $truck->load('donor')], 201);
    }

    public function update(Request $request, $id)
    {
        $truck = Truck::findOrFail($id);
        $data = $request->validate(self::VALIDATION);
        $truck->update($data);

        return response()->json(['record' => $truck->fresh('donor')]);
    }

    public function destroy($id)
    {
        Truck::findOrFail($id)->delete();

        return response()->json(['message' => 'Truck deleted successfully.']);
    }
}
