<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\UseModel;

class UseController extends Controller
{
    private const validation = [
        'use' => 'required|string|max:255|unique:uses,use',
    ];

    /**
     * Retrieve all uses.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $uses = UseModel::all();
        $templates = [
            '_default' => [
                'use' => '',
            ]
        ];

        return response()->json([
            'records' => $uses,
            'templates' => $templates
        ]);
    }

    /**
     * Store a new use.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);
        $use = UseModel::create($data);

        return response()->json([
            'message' => 'Use created successfully.',
            'record' => $use
        ], 201);
    }

    /**
     * Update an existing use.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $use = UseModel::findOrFail($id);

        $data = $request->validate(self::validation + [
            'use' => "required|string|max:255|unique:uses,use,$id"
        ]);

        $use->update($data);

        return response()->json([
            'message' => 'Use updated successfully.',
            'record' => $use
        ], 200);
    }

    /**
     * Delete an existing use.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $use = UseModel::findOrFail($id);
            $use->delete();

            return response()->json([
                'success' => true,
                'message' => 'Use deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting use: ' . $e->getMessage()
            ], 500);
        }
    }
}
