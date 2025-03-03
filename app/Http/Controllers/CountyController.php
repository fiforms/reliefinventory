<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\County;

class CountyController extends Controller
{
    private const validation = [
        'county' => 'required|string|max:255',
        'state' => 'required|string|size:2',
    ];

    /**
     * Retrieve all counties.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $counties = County::all();

        return response()->json([
            'records' => $counties,
            'templates' => [
                '_default' => [
                    'county' => '',
                    'state' => '',
                ]
            ]
        ]);
    }
    
    /**
     * Get a list of unique state abbreviations from the counties table.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function states()
    {
        $states = County::getDistinctStates();
        
        return response()->json([
            'records' => $states
        ]);
    }

    /**
     * Store a new county.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate(self::validation);

        $county = County::create($data);

        return response()->json([
            'message' => 'County created successfully.',
            'record' => $county,
        ], 201);
    }

    /**
     * Update an existing county.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate(self::validation);

        $county = County::findOrFail($id);
        $county->update($data);

        return response()->json([
            'message' => 'County updated successfully.',
            'record' => $county,
        ], 200);
    }

    /**
     * Delete a county.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $county = County::findOrFail($id);
            $county->delete();

            return response()->json([
                'message' => 'County deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting county: ' . $e->getMessage(),
            ], 500);
        }
    }
}
