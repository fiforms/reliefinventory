<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Stream;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    private const VALIDATION = [
        'name' => 'required|string|max:255',
        'warehouse_id' => 'required|exists:warehouses,id',
        'counts_kind' => 'nullable|in:R,W,S,H,Q',
        'counts_status' => 'nullable|string|max:255',
        'counts_condition' => 'nullable|in:pending,good,condemned',
        'threshold' => 'nullable|integer|min:0',
    ];

    public function index()
    {
        $streams = Stream::with('warehouse')->orderBy('name')->get()->map(function ($stream) {
            $stream->current_count = $stream->currentCount();
            $stream->over_threshold = $stream->isOverThreshold();

            return $stream;
        });

        return response()->json([
            'records' => $streams,
            'templates' => [
                '_default' => [
                    'name' => '',
                    'warehouse_id' => null,
                    'counts_kind' => null,
                    'counts_status' => null,
                    'counts_condition' => null,
                    'threshold' => null,
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION);
        $stream = Stream::create($data);

        return response()->json(['record' => $stream->load('warehouse')], 201);
    }

    public function update(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);
        $data = $request->validate(self::VALIDATION);
        $stream->update($data);

        return response()->json(['record' => $stream->fresh('warehouse')]);
    }

    public function destroy($id)
    {
        Stream::findOrFail($id)->delete();

        return response()->json(['message' => 'Stream deleted successfully.']);
    }
}
