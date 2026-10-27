<?php

namespace App\Http\Controllers;

use App\Models\UseModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UseController extends Controller
{
    private const validation = [
        'use' => 'required|string|max:255|unique:uses,use',
    ];

    /**
     * Retrieve all uses.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $uses = UseModel::all();
        $templates = [
            '_default' => [
                'use' => '',
            ],
        ];

        return response()->json([
            'records' => $uses,
            'templates' => $templates,
        ]);
    }

    /**
     * Store a new use.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        $use = UseModel::create($data);

        return response()->json([
            'message' => 'Use created successfully.',
            'record' => $use,
        ], 201);
    }

    /**
     * Update an existing use.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $use = UseModel::findOrFail($id);

        $data = $request->validate(self::validation + [
            'use' => "required|string|max:255|unique:uses,use,$id",
        ]);

        $use->update($data);

        return response()->json([
            'message' => 'Use updated successfully.',
            'record' => $use,
        ], 200);
    }

    /**
     * Delete an existing use.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            $use = UseModel::findOrFail($id);
            $use->delete();

            return response()->json([
                'success' => true,
                'message' => 'Use deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting use: '.$e->getMessage(),
            ], 500);
        }
    }
}
