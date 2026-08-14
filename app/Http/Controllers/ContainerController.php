<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Container;
use Illuminate\Http\Request;

class ContainerController extends Controller
{
    private const VALIDATION = [
        'container_type_id' => 'required|exists:container_types,id',
        'pallet_id' => 'nullable|exists:pallets,id',
        'location_id' => 'nullable|exists:locations,id',
        'description' => 'nullable|string',
    ];

    public function index()
    {
        $containers = Container::with(['containerType', 'pallet', 'location'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'records' => $containers,
            'templates' => [
                '_default' => [
                    'container_type_id' => null,
                    'pallet_id' => null,
                    'location_id' => null,
                    'description' => null,
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION);
        $container = Container::create($data);

        return response()->json(['record' => $container->load(['containerType', 'pallet', 'location'])], 201);
    }

    public function update(Request $request, $id)
    {
        $container = Container::findOrFail($id);
        $data = $request->validate(self::VALIDATION);
        $container->update($data);

        return response()->json(['record' => $container->fresh(['containerType', 'pallet', 'location'])]);
    }

    public function destroy($id)
    {
        Container::findOrFail($id)->delete();

        return response()->json(['message' => 'Container deleted successfully.']);
    }
}
